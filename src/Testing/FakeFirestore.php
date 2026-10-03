<?php

namespace VitisStudio\NativeFirestore\Testing;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Assert as PHPUnit;
use stdClass;
use VitisStudio\NativeFirestore\Contracts\FirestoreDriver;
use VitisStudio\NativeFirestore\DTO\MapValue;
use VitisStudio\NativeFirestore\DTO\Timestamp;
use VitisStudio\NativeFirestore\Events\DocumentChanged;
use VitisStudio\NativeFirestore\Events\QuerySnapshotChanged;
use VitisStudio\NativeFirestore\Events\WriteFailed;
use VitisStudio\NativeFirestore\Exceptions\FirestoreException;
use VitisStudio\NativeFirestore\Support\Path;
use VitisStudio\NativeFirestore\Support\QueryRules;
use VitisStudio\NativeFirestore\Support\ValueCodec;

/**
 * An in-memory Firestore that implements the same driver contract as the
 * native bridge, so the whole PHP API (queries, batches, transactions,
 * listeners, field values) runs unchanged in unit tests.
 *
 * Listener snapshots are dispatched synchronously through Laravel's event
 * dispatcher and recorded in emittedEvents(), whose [event, payload] pairs
 * can be replayed into a NativeComponent test with emitNative().
 *
 * Documents are stored in wire form with JSON objects kept as stdClass, so an
 * empty map and an empty array stay different values, as in Firestore.
 */
class FakeFirestore implements FirestoreDriver
{
    /** @var array<string, stdClass> document path => wire data (maps as stdClass) */
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

    /** @var list<array{code: string, message: string}> */
    private array $rejections = [];

    /** @var (Closure(FakeFirestore, list<string>): void)|null */
    private ?Closure $beforeServerCommit = null;

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
        $this->documents[$path] = $this->typed(ValueCodec::encodeMap($data));
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

        return isset($this->documents[$path]) ? ValueCodec::decodeMap($this->export($this->nativeData($this->documents[$path]))) : null;
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
                $result[Path::lastSegment($path)] = ValueCodec::decodeMap($this->export($this->nativeData($data)));
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
     * Make the backend reject the next commit (permission-denied by default),
     * as security rules would. An awaited commit throws and changes nothing;
     * a commit that does not wait for the server is applied locally, then
     * rolled back with a WriteFailed event, like the SDK's latency compensation.
     */
    public function rejectNextCommit(string $code = FirestoreException::PERMISSION_DENIED, string $message = 'Missing or insufficient permissions.'): static
    {
        $this->rejections[] = ['code' => $code, 'message' => $message];

        return $this;
    }

    /**
     * Run a callback just before the server would see each awaited commit
     * (wait-for-server on), with the fake and the written paths. It can change
     * documents, as another device acting first would, or throw a
     * FirestoreException to reject the commit.
     *
     * @param  (Closure(FakeFirestore, list<string>): void)|null  $callback
     */
    public function beforeServerCommit(?Closure $callback): static
    {
        $this->beforeServerCommit = $callback;

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

        return $this->export($this->documentSnapshot(Path::document($path)));
    }

    /**
     * A commit that waits for the server needs it: offline it fails with
     * unavailable and changes nothing (on a device the call times out
     * instead, with the writes still queued locally).
     */
    public function commitWrites(array $writes, bool $waitForServer): void
    {
        $this->throwPendingFailure();

        $typed = array_map(fn (stdClass|array $write) => (array) $write, (array) $this->typed(array_values($writes)));
        $paths = array_map(fn (array $write) => Path::document((string) $write['path']), $typed);

        if ($waitForServer) {
            $this->guardOnline();

            if ($this->beforeServerCommit !== null) {
                ($this->beforeServerCommit)($this, $paths);
            }

            if ($rejection = array_shift($this->rejections)) {
                throw new FirestoreException($rejection['message'], $rejection['code']);
            }
        }

        $documents = $this->documents;
        $versions = $this->versions;

        foreach ($typed as $index => $write) {
            $documents = $this->applyWrite($documents, $paths[$index], $write);
            $versions[$paths[$index]] = ($versions[$paths[$index]] ?? 0) + 1;
        }

        $before = $this->documents;
        $this->documents = $documents;
        $this->versions = $versions;
        $this->commits[] = $this->wire($writes);

        $this->notifyListeners();

        if (! $waitForServer && $rejection = array_shift($this->rejections)) {
            // The server refused a write that was only applied locally.
            array_pop($this->commits);
            $this->documents = $before;

            foreach ($paths as $path) {
                $this->versions[$path]++;
            }

            $this->notifyListeners();
            $this->emit(WriteFailed::class, ['paths' => array_values(array_unique($paths)), 'code' => $rejection['code'], 'message' => $rejection['message']]);
        }
    }

    public function runQuery(array $query, string $source): array
    {
        $this->throwPendingFailure();
        $this->guardSource($source);

        return $this->export($this->querySnapshot($this->typedQuery($query)));
    }

    public function count(array $query): int
    {
        $this->throwPendingFailure();
        $this->guardOnline();

        return count($this->evaluateQuery($this->typedQuery($query)));
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

        return $this->export($this->documentSnapshot($path));
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
            'target' => ($target['type'] ?? null) === 'document'
                ? $target
                : ['type' => 'query', 'query' => $this->typedQuery((array) ($target['query'] ?? []))],
            'includeMetadataChanges' => $includeMetadataChanges,
            'last' => null,
            'lastData' => [],
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
     * @param  array<string, stdClass>  $documents
     * @param  array<string, mixed>  $write
     * @return array<string, stdClass>
     */
    private function applyWrite(array $documents, string $path, array $write): array
    {
        $existing = $documents[$path] ?? null;
        $data = $write['data'] ?? new stdClass;
        $data = $data instanceof stdClass ? $data : (object) $data;

        switch ($write['type']) {
            case 'delete':
                unset($documents[$path]);

                return $documents;

            case 'update':
                if ($existing === null) {
                    throw new FirestoreException("No document to update: {$path}", FirestoreException::NOT_FOUND);
                }

                foreach (get_object_vars($data) as $fieldPath => $value) {
                    $existing = $this->applyField($existing, explode('.', (string) $fieldPath), $value);
                }

                $documents[$path] = $existing;

                return $documents;

            case 'set':
                if (! empty($write['mergeFields'])) {
                    $result = $existing ?? new stdClass;

                    foreach ($write['mergeFields'] as $fieldPath) {
                        $segments = explode('.', (string) $fieldPath);
                        [$present, $value] = $this->lookup($data, $segments);

                        if (! $present) {
                            throw new FirestoreException("Merge field [{$fieldPath}] is not in the data.", FirestoreException::INVALID_ARGUMENT);
                        }

                        $result = $this->applyField($result, $segments, $value);
                    }

                    $documents[$path] = $result;
                } elseif (! empty($write['merge'])) {
                    $documents[$path] = $this->mergeMap($existing ?? new stdClass, $data);
                } else {
                    $documents[$path] = $this->resolveMap(new stdClass, $data, allowDelete: false);
                }

                return $documents;
        }

        throw new FirestoreException("Unknown write type [{$write['type']}].", FirestoreException::INVALID_ARGUMENT);
    }

    /**
     * Set (or transform / delete) the value at a field path, creating
     * intermediate maps. Maps are never changed in place: snapshots handed
     * out earlier share them.
     *
     * @param  list<string>  $segments
     */
    private function applyField(stdClass $map, array $segments, mixed $value): stdClass
    {
        $map = clone $map;
        $key = array_shift($segments);

        if ($segments === []) {
            if ($this->isSentinel($value, 'delete')) {
                unset($map->{$key});

                return $map;
            }

            $map->{$key} = $this->resolveValue($map->{$key} ?? null, $value);

            return $map;
        }

        $child = $map->{$key} ?? null;
        $map->{$key} = $this->applyField($child instanceof stdClass ? $child : new stdClass, $segments, $value);

        return $map;
    }

    /**
     * Deep-merge for set(..., merge: true).
     */
    private function mergeMap(stdClass $existing, stdClass $data): stdClass
    {
        $existing = clone $existing;

        foreach (get_object_vars($data) as $key => $value) {
            if ($this->isSentinel($value, 'delete')) {
                unset($existing->{$key});
            } elseif ($value instanceof stdClass && get_object_vars($value) !== []) {
                $current = $existing->{$key} ?? null;
                $existing->{$key} = $this->mergeMap($current instanceof stdClass ? $current : new stdClass, $value);
            } else {
                $existing->{$key} = $this->resolveValue($existing->{$key} ?? null, $value);
            }
        }

        return $existing;
    }

    private function resolveMap(stdClass $existing, stdClass $data, bool $allowDelete): stdClass
    {
        $result = new stdClass;

        foreach (get_object_vars($data) as $key => $value) {
            if ($this->isSentinel($value, 'delete')) {
                if (! $allowDelete) {
                    throw new FirestoreException('FieldValue::delete() can only be used with update() or set() with merge.', FirestoreException::INVALID_ARGUMENT);
                }

                continue;
            }

            $current = $existing->{$key} ?? null;
            $result->{$key} = $value instanceof stdClass && get_object_vars($value) !== []
                ? $this->resolveMap($current instanceof stdClass ? $current : new stdClass, $value, $allowDelete)
                : $this->resolveValue($current, $value);
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

        if ($value instanceof stdClass && get_object_vars($value) !== []) {
            return $this->resolveMap($current instanceof stdClass ? $current : new stdClass, $value, allowDelete: false);
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
     * A snapshot with typed data; export() turns it into the bridge shape.
     *
     * @return array<string, mixed>
     */
    private function documentSnapshot(string $path): array
    {
        $exists = array_key_exists($path, $this->documents);

        return [
            'id' => Path::lastSegment($path),
            'path' => $path,
            'exists' => $exists,
            'data' => $exists ? $this->nativeData($this->documents[$path]) : null,
            'metadata' => ['fromCache' => ! $this->networkEnabled, 'hasPendingWrites' => false, 'pendingServerTimestamps' => []],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array<string, mixed>>  $previous
     * @param  array<string, stdClass>  $previousData
     * @return array<string, mixed>
     */
    private function querySnapshot(array $query, array $previous = [], array $previousData = []): array
    {
        $documents = array_map(fn (string $path) => $this->documentSnapshot($path), $this->evaluateQuery($query));

        return [
            'documents' => $documents,
            'changes' => $this->diff($previous, $previousData, $documents, QueryRules::normalizedOrderBy($query)),
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
        $orderBy = QueryRules::normalizedOrderBy($query);
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
            // A null in the list makes not-in match nothing, as in Firestore.
            'not-in' => $value !== null && ! $this->containsValue($operand, null) && ! $this->containsValue($operand, $value),
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

        return isset($this->documents[$path]) ? $this->lookup($this->documents[$path], explode('.', $field)) : [false, null];
    }

    /**
     * @param  list<string>  $segments
     * @return array{0: bool, 1: mixed}
     */
    private function lookup(mixed $current, array $segments): array
    {
        foreach ($segments as $segment) {
            if (! $current instanceof stdClass || ! property_exists($current, $segment)) {
                return [false, null];
            }

            $current = $current->{$segment};
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
            // A snapshot cursor carries the snapshot's own values, so it keeps
            // its position even if the document has changed or gone since.
            $path = Path::document($cursor['document']);
            $values = array_values($cursor['values'] ?? []);

            if (count($values) !== count($orderBy)) {
                throw new FirestoreException(
                    "The cursor for document [{$path}] needs one value per ordered field (".implode(', ', array_column($orderBy, 'field')).').',
                    FirestoreException::INVALID_ARGUMENT,
                );
            }

            foreach ($orderBy as $index => $order) {
                if ($order['field'] === '__name__' && ! (WireValues::isTagged($values[$index], 'reference') && Path::document($values[$index]['path']) === $path)) {
                    throw new FirestoreException("The cursor for document [{$path}] must end in a reference to it.", FirestoreException::INVALID_ARGUMENT);
                }
            }

            return $values;
        }

        $values = array_values($cursor['values']);

        if (count($values) > count($query['orderBy'] ?? [])) {
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
                'data' => $this->wire($snapshot['data']),
                'fromCache' => $snapshot['metadata']['fromCache'],
                'hasPendingWrites' => false,
                'pendingServerTimestamps' => [],
            ]);

            return;
        }

        $snapshot = $this->querySnapshot($target['query'], $listener['last'] ?? [], $listener['lastData']);

        if ($listener['last'] !== null && $snapshot['changes'] === [] && array_column($snapshot['documents'], 'path') === array_column($listener['last'], 'path')) {
            return;
        }

        $this->listeners[$id]['last'] = $snapshot['documents'];
        // Stored maps are never changed in place, so keeping them is enough to
        // compare a document's old version against the new ones later.
        $this->listeners[$id]['lastData'] = array_intersect_key($this->documents, array_flip(array_column($snapshot['documents'], 'path')));

        $this->emit(QuerySnapshotChanged::class, [
            'listenerId' => $id,
            'path' => $target['query']['path'],
            'documents' => $this->wire($snapshot['documents']),
            'changes' => $this->wire($snapshot['changes']),
            'fromCache' => $snapshot['metadata']['fromCache'],
            'hasPendingWrites' => false,
        ]);
    }

    /**
     * Document changes computed the way the SDKs do, so they can be applied
     * one after another to the previous list: removals first, then additions,
     * then modifications (each group in query order), every index taken from
     * a running copy of the list that already reflects the earlier changes.
     * Positions in that running list are found with the query's ordering,
     * because a modified document not yet moved still sits where its old
     * values put it.
     *
     * @param  list<array<string, mixed>>  $old
     * @param  array<string, stdClass>  $oldData
     * @param  list<array<string, mixed>>  $new
     * @param  list<array{field: string, direction: string}>  $orderBy
     * @return list<array<string, mixed>>
     */
    private function diff(array $old, array $oldData, array $new, array $orderBy): array
    {
        $oldByPath = array_column($old, null, 'path');
        $newPaths = array_flip(array_column($new, 'path'));

        $removed = array_values(array_filter($old, fn (array $document) => ! isset($newPaths[$document['path']])));
        $added = array_values(array_filter($new, fn (array $document) => ! isset($oldByPath[$document['path']])));
        $modified = array_values(array_filter(
            $new,
            fn (array $document) => isset($oldByPath[$document['path']]) && $oldByPath[$document['path']]['data'] != $document['data'],
        ));

        /** @var list<array{path: string, data: stdClass}> $tracker */
        $tracker = array_map(fn (array $document) => ['path' => $document['path'], 'data' => $oldData[$document['path']] ?? new stdClass], $old);
        $changes = [];

        $indexOf = function (string $path) use (&$tracker): int {
            foreach ($tracker as $index => $entry) {
                if ($entry['path'] === $path) {
                    return $index;
                }
            }

            throw new LogicException("Document [{$path}] is not in the previous snapshot.");
        };

        $insert = function (string $path) use (&$tracker, $orderBy): int {
            $entry = ['path' => $path, 'data' => $this->documents[$path]];
            $position = count($tracker);

            foreach ($tracker as $index => $other) {
                if ($this->compareVersions($orderBy, $entry, $other) < 0) {
                    $position = $index;

                    break;
                }
            }

            array_splice($tracker, $position, 0, [$entry]);

            return $position;
        };

        foreach ($removed as $document) {
            $index = $indexOf($document['path']);
            array_splice($tracker, $index, 1);
            $changes[] = ['type' => 'removed', 'document' => $document, 'oldIndex' => $index, 'newIndex' => -1];
        }

        foreach ($added as $document) {
            $changes[] = ['type' => 'added', 'document' => $document, 'oldIndex' => -1, 'newIndex' => $insert($document['path'])];
        }

        foreach ($modified as $document) {
            $index = $indexOf($document['path']);
            array_splice($tracker, $index, 1);
            $changes[] = ['type' => 'modified', 'document' => $document, 'oldIndex' => $index, 'newIndex' => $insert($document['path'])];
        }

        return $changes;
    }

    /**
     * Compare two document versions (path plus stored data) by the query ordering.
     *
     * @param  list<array{field: string, direction: string}>  $orderBy
     * @param  array{path: string, data: stdClass}  $a
     * @param  array{path: string, data: stdClass}  $b
     */
    private function compareVersions(array $orderBy, array $a, array $b): int
    {
        $value = fn (array $version, string $field) => $field === '__name__'
            ? ['__type' => 'reference', 'path' => $version['path']]
            : $this->lookup($version['data'], explode('.', $field))[1];

        foreach ($orderBy as $order) {
            $result = WireValues::compare($value($a, $order['field']), $value($b, $order['field']));

            if ($result !== 0) {
                return $order['direction'] === 'desc' ? -$result : $result;
            }
        }

        return 0;
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
     * Normalise a payload as JSON decoded to PHP arrays: what event payloads
     * look like after NativePHP delivers them.
     */
    private function wire(mixed $payload): mixed
    {
        return json_decode(ValueCodec::json($payload), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Typed data back in the shape the native bridge returns, as consumers
     * see it.
     */
    private function export(mixed $value): mixed
    {
        return ValueCodec::fromJson(ValueCodec::json($value));
    }

    /**
     * Stored document data as native code sends it: nested maps that PHP
     * would mistake for lists (empty, or keyed "0".."n-1") tagged as maps.
     */
    private function nativeData(stdClass $data): stdClass
    {
        $result = new stdClass;

        foreach (get_object_vars($data) as $key => $value) {
            $result->{$key} = $this->nativeValue($value);
        }

        return $result;
    }

    private function nativeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return WireValues::isTagged($value) ? $value : array_map($this->nativeValue(...), $value);
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $map = $this->nativeData($value);

        return MapValue::isAmbiguous(get_object_vars($map))
            ? [ValueCodec::TYPE_KEY => ValueCodec::TYPE_MAP, 'value' => $map]
            : $map;
    }

    /**
     * A payload through JSON with objects kept as stdClass, so maps stay maps
     * (an empty map, or one with numeric keys, is not a list). Tagged values
     * become arrays again.
     */
    private function typed(mixed $payload): mixed
    {
        return $this->normalize(json_decode(ValueCodec::json($payload), false, 512, JSON_THROW_ON_ERROR));
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->normalize(...), $value);
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $properties = get_object_vars($value);

        if (isset($properties['__type']) && is_string($properties['__type'])) {
            return array_map($this->normalize(...), $properties);
        }

        $map = new stdClass;

        foreach ($properties as $key => $child) {
            $map->{$key} = $this->normalize($child);
        }

        return $map;
    }

    /**
     * The query envelope as arrays, its operands and cursor values typed.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function typedQuery(array $query): array
    {
        $typed = (array) $this->typed($query);
        $typed['filters'] = array_map($this->typedFilter(...), (array) ($typed['filters'] ?? []));
        $typed['orderBy'] = array_map(fn (stdClass|array $order) => (array) $order, (array) ($typed['orderBy'] ?? []));

        foreach (['startAt', 'endAt'] as $edge) {
            $typed[$edge] = isset($typed[$edge]) ? (array) $typed[$edge] : null;
        }

        return $typed;
    }

    /**
     * @return array<string, mixed>
     */
    private function typedFilter(stdClass|array $filter): array
    {
        $filter = (array) $filter;

        if (isset($filter['composite'])) {
            $filter['filters'] = array_map($this->typedFilter(...), (array) ($filter['filters'] ?? []));
        }

        return $filter;
    }
}
