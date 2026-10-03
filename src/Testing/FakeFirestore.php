<?php

namespace VitisStudio\NativeFirestore\Testing;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use VitisStudio\NativeFirestore\Contracts\FirestoreDriver;
use VitisStudio\NativeFirestore\DTO\Timestamp;
use VitisStudio\NativeFirestore\Events\DocumentChanged;
use VitisStudio\NativeFirestore\Events\QuerySnapshotChanged;
use VitisStudio\NativeFirestore\Exceptions\FirestoreException;
use VitisStudio\NativeFirestore\Support\Path;
use VitisStudio\NativeFirestore\Support\ValueCodec;

/**
 * An in-memory Firestore that implements the same driver contract as the
 * native bridge, so the whole PHP API (queries, batches, transactions,
 * listeners, field values) runs unchanged in unit tests.
 *
 * Listener snapshots are dispatched synchronously through Laravel's event
 * dispatcher and recorded in emittedEvents(), whose [event, payload] pairs
 * can be replayed into a NativeComponent test with emitNative().
 */
class FakeFirestore implements FirestoreDriver
{
    /** @var array<string, array<string, mixed>> document path => wire data */
    private array $documents = [];

    /** @var array<string, int> document path => write counter, for transaction conflicts */
    private array $versions = [];

    /** @var array<string, array{maxAttempts: int, attempt: int, reads: array<string, int>}> */
    private array $transactions = [];

    /** @var array<string, array{target: array<string, mixed>, includeMetadataChanges: bool, last: mixed}> */
    private array $listeners = [];

    /** @var list<array{0: class-string, 1: array<string, mixed>}> */
    private array $emitted = [];

    /** @var list<list<array<string, mixed>>> */
    private array $commits = [];

    private bool $networkEnabled = true;

    /** @var list<array{code: string, message: string}> */
    private array $failures = [];

    // -------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------

    /**
     * Put a document in place without going through the write path (no
     * listeners fire, nothing is recorded as a commit).
     *
     * @param  array<string, mixed>  $data
     */
    public function seed(string $path, array $data): static
    {
        $path = Path::document($path);
        $this->documents[$path] = $this->wire(ValueCodec::encodeMap($data));
        $this->versions[$path] = ($this->versions[$path] ?? 0) + 1;

        return $this;
    }

    /**
     * @param  array<string, array<string, mixed>>  $documents  id => data
     */
    public function seedCollection(string $collectionPath, array $documents): static
    {
        $collectionPath = Path::collection($collectionPath);

        foreach ($documents as $id => $data) {
            $this->seed($collectionPath.'/'.$id, $data);
        }

        return $this;
    }

    /**
     * The decoded data stored at a path, or null.
     *
     * @return array<string, mixed>|null
     */
    public function document(string $path): ?array
    {
        $path = Path::document($path);

        return isset($this->documents[$path]) ? ValueCodec::decodeMap($this->documents[$path]) : null;
    }

    /**
     * Decoded documents directly inside a collection, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function documents(string $collectionPath): array
    {
        $collectionPath = Path::collection($collectionPath);
        $result = [];

        foreach ($this->documents as $path => $data) {
            if (Path::parent($path) === $collectionPath) {
                $result[Path::lastSegment($path)] = ValueCodec::decodeMap($data);
            }
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Make the next driver call fail with this Firestore error code.
     */
    public function failNext(string $code, string $message = 'Simulated failure.'): static
    {
        $this->failures[] = ['code' => $code, 'message' => $message];

        return $this;
    }

    /**
     * @return list<array{0: class-string, 1: array<string, mixed>}>
     */
    public function emittedEvents(): array
    {
        return $this->emitted;
    }

    /**
     * @return list<list<array<string, mixed>>> every committed write set
     */
    public function commits(): array
    {
        return $this->commits;
    }

    /**
     * @return list<string>
     */
    public function activeListeners(): array
    {
        return array_keys($this->listeners);
    }

    public function isNetworkEnabled(): bool
    {
        return $this->networkEnabled;
    }

    /**
     * @param  (callable(array<string, mixed>): bool)|array<string, mixed>|null  $expected
     */
    public function assertExists(string $path, callable|array|null $expected = null): static
    {
        $data = $this->document($path);

        PHPUnit::assertNotNull($data, "Expected Firestore document [{$path}] to exist.");

        if (is_array($expected)) {
            foreach ($expected as $key => $value) {
                PHPUnit::assertEquals($value, Arr::get($data, $key), "Field [{$key}] of [{$path}] does not match.");
            }
        } elseif ($expected !== null) {
            PHPUnit::assertTrue($expected($data), "Firestore document [{$path}] did not pass the given truth test.");
        }

        return $this;
    }

    public function assertMissing(string $path): static
    {
        PHPUnit::assertNull($this->document($path), "Expected Firestore document [{$path}] not to exist.");

        return $this;
    }

    public function assertCount(string $collectionPath, int $count): static
    {
        PHPUnit::assertCount($count, $this->documents($collectionPath), "Unexpected document count in [{$collectionPath}].");

        return $this;
    }

    public function assertListening(?string $listenerId = null): static
    {
        $listenerId === null
            ? PHPUnit::assertNotEmpty($this->listeners, 'Expected at least one active Firestore listener.')
            : PHPUnit::assertArrayHasKey($listenerId, $this->listeners, "Expected listener [{$listenerId}] to be active.");

        return $this;
    }

    public function assertNotListening(?string $listenerId = null): static
    {
        $listenerId === null
            ? PHPUnit::assertEmpty($this->listeners, 'Expected no active Firestore listeners.')
            : PHPUnit::assertArrayNotHasKey($listenerId, $this->listeners, "Expected listener [{$listenerId}] to be stopped.");

        return $this;
    }

    // -------------------------------------------------------------------
    // FirestoreDriver
    // -------------------------------------------------------------------

    public function getDocument(string $path, string $source): array
    {
        $this->throwPendingFailure();
        $this->guardSource($source);

        return $this->documentSnapshot(Path::document($path));
    }

    public function commitWrites(array $writes, bool $waitForServer): void
    {
        $this->throwPendingFailure();

        $writes = $this->wire($writes);
        $documents = $this->documents;
        $versions = $this->versions;

        foreach ($writes as $write) {
            $path = Path::document((string) $write['path']);
            $documents = $this->applyWrite($documents, $path, $write);
            $versions[$path] = ($versions[$path] ?? 0) + 1;
        }

        $this->documents = $documents;
        $this->versions = $versions;
        $this->commits[] = $writes;

        $this->notifyListeners();
    }

    public function runQuery(array $query, string $source): array
    {
        $this->throwPendingFailure();
        $this->guardSource($source);

        return $this->querySnapshot($this->wire($query));
    }

    public function count(array $query): int
    {
        $this->throwPendingFailure();
        $this->guardOnline();

        return count($this->evaluateQuery($this->wire($query)));
    }

    public function beginTransaction(int $maxAttempts): string
    {
        $this->throwPendingFailure();
        $this->guardOnline();

        $id = (string) Str::uuid();
        $this->transactions[$id] = ['maxAttempts' => $maxAttempts, 'attempt' => 1, 'reads' => []];

        return $id;
    }

    public function transactionGet(string $transactionId, string $path): array
    {
        $this->throwPendingFailure();
        $this->transaction($transactionId);

        $path = Path::document($path);
        $this->transactions[$transactionId]['reads'][$path] = $this->versions[$path] ?? 0;

        return $this->documentSnapshot($path);
    }

    public function commitTransaction(string $transactionId, array $writes): bool
    {
        $this->throwPendingFailure();
        $transaction = $this->transaction($transactionId);

        foreach ($transaction['reads'] as $path => $version) {
            if (($this->versions[$path] ?? 0) !== $version) {
                return $this->retryTransaction($transactionId);
            }
        }

        unset($this->transactions[$transactionId]);

        if ($writes !== []) {
            $this->commitWrites($writes, true);
        }

        return true;
    }

    public function abortTransaction(string $transactionId): void
    {
        unset($this->transactions[$transactionId]);
    }

    public function listen(array $target, bool $includeMetadataChanges): string
    {
        $this->throwPendingFailure();

        $id = (string) Str::uuid();
        $this->listeners[$id] = [
            'target' => $this->wire($target),
            'includeMetadataChanges' => $includeMetadataChanges,
            'last' => null,
        ];

        $this->notifyListener($id);

        return $id;
    }

    public function stopListening(string $listenerId): void
    {
        unset($this->listeners[$listenerId]);
    }

    public function stopAllListeners(): void
    {
        $this->listeners = [];
    }

    public function enableNetwork(): void
    {
        $this->networkEnabled = true;
    }

    public function disableNetwork(): void
    {
        $this->networkEnabled = false;
    }

    public function waitForPendingWrites(): void
    {
        $this->guardOnline();
    }

    public function status(): array
    {
        return ['configured' => true, 'fake' => true, 'projectId' => 'fake-project', 'emulator' => false];
    }

    // -------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $documents
     * @param  array<string, mixed>  $write
     * @return array<string, array<string, mixed>>
     */
    private function applyWrite(array $documents, string $path, array $write): array
    {
        $existing = $documents[$path] ?? null;
        $data = (array) ($write['data'] ?? []);

        switch ($write['type']) {
            case 'delete':
                unset($documents[$path]);

                return $documents;

            case 'update':
                if ($existing === null) {
                    throw new FirestoreException("No document to update: {$path}", FirestoreException::NOT_FOUND);
                }

                foreach ($data as $fieldPath => $value) {
                    $existing = $this->applyField($existing, explode('.', (string) $fieldPath), $value);
                }

                $documents[$path] = $existing;

                return $documents;

            case 'set':
                if (! empty($write['mergeFields'])) {
                    $result = $existing ?? [];

                    foreach ($write['mergeFields'] as $fieldPath) {
                        $segments = explode('.', (string) $fieldPath);

                        if (! Arr::has($data, $fieldPath)) {
                            throw new FirestoreException("Merge field [{$fieldPath}] is not in the data.", FirestoreException::INVALID_ARGUMENT);
                        }

                        $result = $this->applyField($result, $segments, Arr::get($data, $fieldPath));
                    }

                    $documents[$path] = $result;
                } elseif (! empty($write['merge'])) {
                    $documents[$path] = $this->mergeMap($existing ?? [], $data);
                } else {
                    $documents[$path] = $this->resolveMap([], $data, allowDelete: false);
                }

                return $documents;
        }

        throw new FirestoreException("Unknown write type [{$write['type']}].", FirestoreException::INVALID_ARGUMENT);
    }

    /**
     * Set (or transform / delete) the value at a field path, creating
     * intermediate maps.
     *
     * @param  array<string, mixed>  $map
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private function applyField(array $map, array $segments, mixed $value): array
    {
        $key = array_shift($segments);

        if ($segments === []) {
            if ($this->isSentinel($value, 'delete')) {
                unset($map[$key]);

                return $map;
            }

            $map[$key] = $this->resolveValue($map[$key] ?? null, $value);

            return $map;
        }

        $child = $map[$key] ?? [];
        $map[$key] = $this->applyField(WireValues::isMap($child) ? $child : [], $segments, $value);

        return $map;
    }

    /**
     * Deep-merge for set(..., merge: true).
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeMap(array $existing, array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSentinel($value, 'delete')) {
                unset($existing[$key]);
            } elseif (WireValues::isMap($value) && $value !== []) {
                $current = $existing[$key] ?? [];
                $existing[$key] = $this->mergeMap(WireValues::isMap($current) ? $current : [], $value);
            } else {
                $existing[$key] = $this->resolveValue($existing[$key] ?? null, $value);
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveMap(array $existing, array $data, bool $allowDelete): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($this->isSentinel($value, 'delete')) {
                if (! $allowDelete) {
                    throw new FirestoreException('FieldValue::delete() can only be used with update() or set() with merge.', FirestoreException::INVALID_ARGUMENT);
                }

                continue;
            }

            $result[$key] = WireValues::isMap($value) && $value !== []
                ? $this->resolveMap(WireValues::isMap($existing[$key] ?? null) ? $existing[$key] : [], $value, $allowDelete)
                : $this->resolveValue($existing[$key] ?? null, $value);
        }

        return $result;
    }

    private function resolveValue(mixed $current, mixed $value): mixed
    {
        if ($this->isSentinel($value)) {
            return match ($value['op']) {
                'serverTimestamp' => Timestamp::now()->toWire(),
                'increment' => (is_int($current) || is_float($current) ? $current : 0) + $value['value'],
                'arrayUnion' => $this->arrayUnion(WireValues::isList($current) ? $current : [], $value['value']),
                'arrayRemove' => array_values(array_filter(
                    WireValues::isList($current) ? $current : [],
                    fn (mixed $element) => ! $this->containsValue($value['value'], $element),
                )),
                default => throw new FirestoreException("Unsupported field value [{$value['op']}].", FirestoreException::INVALID_ARGUMENT),
            };
        }

        if (WireValues::isMap($value) && $value !== []) {
            return $this->resolveMap(WireValues::isMap($current) ? $current : [], $value, allowDelete: false);
        }

        if (WireValues::isList($value)) {
            foreach ($value as $element) {
                if ($this->isSentinel($element)) {
                    throw new FirestoreException('Field values cannot be used inside arrays.', FirestoreException::INVALID_ARGUMENT);
                }
            }
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $current
     * @param  list<mixed>  $elements
     * @return list<mixed>
     */
    private function arrayUnion(array $current, array $elements): array
    {
        foreach ($elements as $element) {
            if (! $this->containsValue($current, $element)) {
                $current[] = $element;
            }
        }

        return $current;
    }

    /**
     * @param  list<mixed>  $haystack
     */
    private function containsValue(array $haystack, mixed $needle): bool
    {
        foreach ($haystack as $element) {
            if (WireValues::equals($element, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isSentinel(mixed $value, ?string $operation = null): bool
    {
        return WireValues::isTagged($value, 'fieldvalue') && ($operation === null || $value['op'] === $operation);
    }

    // -------------------------------------------------------------------
    // Reads & queries
    // -------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function documentSnapshot(string $path): array
    {
        $exists = array_key_exists($path, $this->documents);

        return [
            'id' => Path::lastSegment($path),
            'path' => $path,
            'exists' => $exists,
            'data' => $exists ? $this->documents[$path] : null,
            'metadata' => ['fromCache' => ! $this->networkEnabled, 'hasPendingWrites' => false],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function querySnapshot(array $query, ?array $previous = null): array
    {
        $documents = array_map(fn (string $path) => $this->documentSnapshot($path), $this->evaluateQuery($query));

        return [
            'documents' => $documents,
            'changes' => $this->diff($previous ?? [], $documents),
            'metadata' => ['fromCache' => ! $this->networkEnabled, 'hasPendingWrites' => false],
        ];
    }

    /**
     * The ordered list of matching document paths.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function evaluateQuery(array $query): array
    {
        $orderBy = $this->effectiveOrderBy($query);
        $context = $query;

        $paths = array_values(array_filter(
            array_keys($this->documents),
            fn (string $path) => $this->inScope($query, $path)
                && $this->matchesAll($query['filters'] ?? [], $path, $context)
                && $this->hasOrderFields($orderBy, $path),
        ));

        usort($paths, fn (string $a, string $b) => $this->compareDocuments($orderBy, $a, $b));

        if (! empty($query['startAt'])) {
            $cursor = $this->cursorValues($query['startAt'], $orderBy, $query);
            $paths = array_values(array_filter($paths, function (string $path) use ($cursor, $orderBy, $query) {
                $result = $this->compareToCursor($orderBy, $path, $cursor);

                return $query['startAt']['inclusive'] ? $result >= 0 : $result > 0;
            }));
        }

        if (! empty($query['endAt'])) {
            $cursor = $this->cursorValues($query['endAt'], $orderBy, $query);
            $paths = array_values(array_filter($paths, function (string $path) use ($cursor, $orderBy, $query) {
                $result = $this->compareToCursor($orderBy, $path, $cursor);

                return $query['endAt']['inclusive'] ? $result <= 0 : $result < 0;
            }));
        }

        if (! empty($query['limit'])) {
            $paths = ($query['limitType'] ?? 'first') === 'last'
                ? array_slice($paths, -$query['limit'])
                : array_slice($paths, 0, $query['limit']);
        }

        return array_values($paths);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function inScope(array $query, string $path): bool
    {
        $parent = (string) Path::parent($path);

        return ! empty($query['collectionGroup'])
            ? Path::lastSegment($parent) === $query['path']
            : $parent === $query['path'];
    }

    /**
     * Explicit orderBy clauses, the implicit inequality-field ordering, then
     * the document name as the final tie-breaker.
     *
     * @param  array<string, mixed>  $query
     * @return list<array{field: string, direction: string}>
     */
    private function effectiveOrderBy(array $query): array
    {
        $orderBy = array_values($query['orderBy'] ?? []);

        if ($orderBy === []) {
            $field = $this->firstInequalityField($query['filters'] ?? []);

            if ($field !== null) {
                $orderBy[] = ['field' => $field, 'direction' => 'asc'];
            }
        }

        $fields = array_column($orderBy, 'field');

        if (! in_array('__name__', $fields, true)) {
            $last = end($orderBy);
            $orderBy[] = ['field' => '__name__', 'direction' => $last['direction'] ?? 'asc'];
        }

        return $orderBy;
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     */
    private function firstInequalityField(array $filters): ?string
    {
        foreach ($filters as $filter) {
            if (isset($filter['composite'])) {
                $field = $this->firstInequalityField($filter['filters']);
            } else {
                $field = in_array($filter['op'], ['!=', '<', '<=', '>', '>=', 'not-in'], true) ? $filter['field'] : null;
            }

            if ($field !== null) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     * @param  array<string, mixed>  $query
     */
    private function matchesAll(array $filters, string $path, array $query): bool
    {
        foreach ($filters as $filter) {
            if (! $this->matches($filter, $path, $query)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  array<string, mixed>  $query
     */
    private function matches(array $filter, string $path, array $query): bool
    {
        if (isset($filter['composite'])) {
            if ($filter['composite'] === 'and') {
                return $this->matchesAll($filter['filters'], $path, $query);
            }

            foreach ($filter['filters'] as $child) {
                if ($this->matches($child, $path, $query)) {
                    return true;
                }
            }

            return false;
        }

        [$exists, $value] = $this->field($path, $filter['field']);
        $operand = $filter['field'] === '__name__'
            ? $this->nameOperand($filter['value'], $query)
            : $filter['value'];

        if (! $exists) {
            return false;
        }

        return match ($filter['op']) {
            '==' => WireValues::equals($value, $operand),
            '!=' => $value !== null && ! WireValues::equals($value, $operand),
            '<' => $this->sameType($value, $operand) && WireValues::compare($value, $operand) < 0,
            '<=' => $this->sameType($value, $operand) && WireValues::compare($value, $operand) <= 0,
            '>' => $this->sameType($value, $operand) && WireValues::compare($value, $operand) > 0,
            '>=' => $this->sameType($value, $operand) && WireValues::compare($value, $operand) >= 0,
            'array-contains' => WireValues::isList($value) && $this->containsValue($value, $operand),
            'array-contains-any' => WireValues::isList($value) && array_filter($operand, fn ($candidate) => $this->containsValue($value, $candidate)) !== [],
            'in' => $this->containsValue($operand, $value),
            'not-in' => $value !== null && ! $this->containsValue($operand, $value),
            default => throw new FirestoreException("Unsupported operator [{$filter['op']}].", FirestoreException::INVALID_ARGUMENT),
        };
    }

    private function sameType(mixed $a, mixed $b): bool
    {
        return WireValues::typeOrder($a) === WireValues::typeOrder($b);
    }

    /**
     * A plain string compared against __name__ is a document id relative to
     * the queried collection, as in the SDKs.
     *
     * @param  array<string, mixed>  $query
     */
    private function nameOperand(mixed $value, array $query): mixed
    {
        if (is_string($value)) {
            return ['__type' => 'reference', 'path' => empty($query['collectionGroup']) ? $query['path'].'/'.$value : $value];
        }

        if (WireValues::isList($value)) {
            return array_map(fn (mixed $item) => $this->nameOperand($item, $query), $value);
        }

        return $value;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function field(string $path, string $field): array
    {
        if ($field === '__name__') {
            return [true, ['__type' => 'reference', 'path' => $path]];
        }

        $current = $this->documents[$path] ?? [];

        foreach (explode('.', $field) as $segment) {
            if (! is_array($current) || WireValues::isTagged($current) || ! array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * @param  list<array{field: string, direction: string}>  $orderBy
     */
    private function hasOrderFields(array $orderBy, string $path): bool
    {
        foreach ($orderBy as $order) {
            if (! $this->field($path, $order['field'])[0]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{field: string, direction: string}>  $orderBy
     */
    private function compareDocuments(array $orderBy, string $a, string $b): int
    {
        foreach ($orderBy as $order) {
            $result = WireValues::compare($this->field($a, $order['field'])[1], $this->field($b, $order['field'])[1]);

            if ($result !== 0) {
                return $order['direction'] === 'desc' ? -$result : $result;
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $cursor
     * @param  list<array{field: string, direction: string}>  $orderBy
     * @param  array<string, mixed>  $query
     * @return list<mixed>
     */
    private function cursorValues(array $cursor, array $orderBy, array $query): array
    {
        if (isset($cursor['document'])) {
            $path = Path::document($cursor['document']);

            if (! isset($this->documents[$path])) {
                throw new FirestoreException("Cursor document [{$path}] does not exist.", FirestoreException::INVALID_ARGUMENT);
            }

            return array_map(fn (array $order) => $this->field($path, $order['field'])[1], $orderBy);
        }

        $values = array_values($cursor['values']);

        if (count($values) > count($orderBy)) {
            throw new FirestoreException('Too many cursor values for the query ordering.', FirestoreException::INVALID_ARGUMENT);
        }

        foreach ($values as $index => $value) {
            if ($orderBy[$index]['field'] === '__name__') {
                $values[$index] = $this->nameOperand($value, $query);
            }
        }

        return $values;
    }

    /**
     * @param  list<array{field: string, direction: string}>  $orderBy
     * @param  list<mixed>  $cursor
     */
    private function compareToCursor(array $orderBy, string $path, array $cursor): int
    {
        foreach ($cursor as $index => $value) {
            $result = WireValues::compare($this->field($path, $orderBy[$index]['field'])[1], $value);

            if ($result !== 0) {
                return $orderBy[$index]['direction'] === 'desc' ? -$result : $result;
            }
        }

        return 0;
    }

    // -------------------------------------------------------------------
    // Listeners
    // -------------------------------------------------------------------

    private function notifyListeners(): void
    {
        foreach (array_keys($this->listeners) as $id) {
            if (isset($this->listeners[$id])) {
                $this->notifyListener($id);
            }
        }
    }

    private function notifyListener(string $id): void
    {
        $listener = $this->listeners[$id];
        $target = $listener['target'];

        if ($target['type'] === 'document') {
            $snapshot = $this->documentSnapshot(Path::document($target['path']));
            $state = [$snapshot['exists'], $snapshot['data']];

            if ($listener['last'] !== null && $listener['last'] == $state) {
                return;
            }

            $this->listeners[$id]['last'] = $state;

            $this->emit(DocumentChanged::class, [
                'listenerId' => $id,
                'path' => $snapshot['path'],
                'id' => $snapshot['id'],
                'exists' => $snapshot['exists'],
                'data' => $snapshot['data'],
                'fromCache' => $snapshot['metadata']['fromCache'],
                'hasPendingWrites' => false,
            ]);

            return;
        }

        $snapshot = $this->querySnapshot($target['query'], $listener['last'] ?? []);

        if ($listener['last'] !== null && $snapshot['changes'] === [] && array_column($snapshot['documents'], 'path') === array_column($listener['last'], 'path')) {
            return;
        }

        $this->listeners[$id]['last'] = $snapshot['documents'];

        $this->emit(QuerySnapshotChanged::class, [
            'listenerId' => $id,
            'path' => $target['query']['path'],
            'documents' => $snapshot['documents'],
            'changes' => $snapshot['changes'],
            'fromCache' => $snapshot['metadata']['fromCache'],
            'hasPendingWrites' => false,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $old
     * @param  list<array<string, mixed>>  $new
     * @return list<array<string, mixed>>
     */
    private function diff(array $old, array $new): array
    {
        $oldIndex = array_flip(array_column($old, 'path'));
        $newIndex = array_flip(array_column($new, 'path'));
        $changes = [];

        foreach ($old as $index => $document) {
            if (! isset($newIndex[$document['path']])) {
                $changes[] = ['type' => 'removed', 'document' => $document, 'oldIndex' => $index, 'newIndex' => -1];
            }
        }

        foreach ($new as $index => $document) {
            if (! isset($oldIndex[$document['path']])) {
                $changes[] = ['type' => 'added', 'document' => $document, 'oldIndex' => -1, 'newIndex' => $index];
            } elseif ($old[$oldIndex[$document['path']]]['data'] != $document['data']) {
                $changes[] = ['type' => 'modified', 'document' => $document, 'oldIndex' => $oldIndex[$document['path']], 'newIndex' => $index];
            }
        }

        return $changes;
    }

    /**
     * @param  class-string  $event
     * @param  array<string, mixed>  $payload
     */
    private function emit(string $event, array $payload): void
    {
        $this->emitted[] = [$event, $payload];

        event(new $event(...$payload));
    }

    // -------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------

    /**
     * @return array{maxAttempts: int, attempt: int, reads: array<string, int>}
     */
    private function transaction(string $transactionId): array
    {
        return $this->transactions[$transactionId]
            ?? throw new FirestoreException("Unknown or finished transaction [{$transactionId}].", FirestoreException::FAILED_PRECONDITION);
    }

    private function retryTransaction(string $transactionId): bool
    {
        $transaction = $this->transactions[$transactionId];

        if ($transaction['attempt'] >= $transaction['maxAttempts']) {
            unset($this->transactions[$transactionId]);

            throw new FirestoreException(
                "Transaction failed after {$transaction['maxAttempts']} attempts because the documents it read kept changing.",
                FirestoreException::ABORTED,
            );
        }

        $this->transactions[$transactionId]['attempt']++;
        $this->transactions[$transactionId]['reads'] = [];

        return false;
    }

    private function guardSource(string $source): void
    {
        if ($source === 'server') {
            $this->guardOnline();
        }
    }

    private function guardOnline(): void
    {
        if (! $this->networkEnabled) {
            throw new FirestoreException('The client is offline (network disabled).', FirestoreException::UNAVAILABLE);
        }
    }

    private function throwPendingFailure(): void
    {
        if ($failure = array_shift($this->failures)) {
            throw new FirestoreException($failure['message'], $failure['code']);
        }
    }

    /**
     * Normalise a payload exactly as the JSON bridge would.
     */
    private function wire(mixed $payload): mixed
    {
        return json_decode(ValueCodec::json($payload), true, 512, JSON_THROW_ON_ERROR);
    }
}
