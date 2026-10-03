<?php

namespace VitisStudio\NativeFirestore\Query;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use VitisStudio\NativeFirestore\DTO\DocumentSnapshot;
use VitisStudio\NativeFirestore\DTO\QuerySnapshot;
use VitisStudio\NativeFirestore\FirestoreClient;
use VitisStudio\NativeFirestore\Support\QueryRules;
use VitisStudio\NativeFirestore\Support\ValueCodec;

/**
 * An immutable Firestore query: every builder method returns a new instance,
 * so a base query can be reused safely.
 */
class Query
{
    /** @var list<Filter> */
    protected array $filters = [];

    /** @var list<array{field: string, direction: 'asc'|'desc'}> */
    protected array $orderBy = [];

    protected ?int $limit = null;

    protected string $limitType = 'first';

    /** @var array{values?: list<mixed>, document?: string, inclusive: bool}|null */
    protected ?array $startCursor = null;

    /** @var array{values?: list<mixed>, document?: string, inclusive: bool}|null */
    protected ?array $endCursor = null;

    /** @var array{start?: DocumentSnapshot, end?: DocumentSnapshot} */
    protected array $cursorSnapshots = [];

    public function __construct(
        protected readonly string $path,
        protected readonly bool $collectionGroup = false,
        protected ?FirestoreClient $client = null,
    ) {}

    /**
     * where('status', 'open'), where('age', '>=', 18) or where(Filter::or(...)).
     */
    public function where(string|Filter $field, mixed $operator = null, mixed $value = null): static
    {
        if ($field instanceof Filter) {
            $filter = $field;
        } elseif (func_num_args() === 2) {
            $filter = Filter::where($field, '==', $operator);
        } else {
            $filter = Filter::where($field, (string) $operator, $value);
        }

        $query = clone $this;
        $query->filters[] = $filter;

        return $query;
    }

    /**
     * @param  list<mixed>  $values
     */
    public function whereIn(string $field, array $values): static
    {
        return $this->where($field, 'in', $values);
    }

    /**
     * @param  list<mixed>  $values
     */
    public function whereNotIn(string $field, array $values): static
    {
        return $this->where($field, 'not-in', $values);
    }

    public function whereArrayContains(string $field, mixed $value): static
    {
        return $this->where($field, 'array-contains', $value);
    }

    /**
     * @param  list<mixed>  $values
     */
    public function whereArrayContainsAny(string $field, array $values): static
    {
        return $this->where($field, 'array-contains-any', $values);
    }

    public function orderBy(string $field, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("Order direction must be 'asc' or 'desc', [{$direction}] given.");
        }

        $query = clone $this;
        $query->orderBy[] = ['field' => $field, 'direction' => $direction];

        return $query;
    }

    public function orderByDesc(string $field): static
    {
        return $this->orderBy($field, 'desc');
    }

    public function limit(int $limit): static
    {
        return $this->withLimit($limit, 'first');
    }

    /**
     * The last $limit results of the ordered query, returned in query order.
     * Requires at least one orderBy(), like the native SDKs.
     */
    public function limitToLast(int $limit): static
    {
        return $this->withLimit($limit, 'last');
    }

    public function startAt(mixed ...$values): static
    {
        return $this->withCursor('start', $values, inclusive: true);
    }

    public function startAfter(mixed ...$values): static
    {
        return $this->withCursor('start', $values, inclusive: false);
    }

    public function endAt(mixed ...$values): static
    {
        return $this->withCursor('end', $values, inclusive: true);
    }

    public function endBefore(mixed ...$values): static
    {
        return $this->withCursor('end', $values, inclusive: false);
    }

    public function get(Source $source = Source::Default): QuerySnapshot
    {
        return QuerySnapshot::fromArray($this->client()->driver()->runQuery($this->toWire(), $source->value));
    }

    public function first(Source $source = Source::Default): ?DocumentSnapshot
    {
        return $this->limit(1)->get($source)->first();
    }

    /**
     * Server-side count() aggregation. Needs a connection; it is not served
     * from the offline cache.
     */
    public function count(): int
    {
        return $this->client()->driver()->count($this->toWire());
    }

    /**
     * Start a realtime listener; QuerySnapshotChanged events carry the
     * snapshots. Returns the listener id for Firestore::stop().
     */
    public function listen(bool $includeMetadataChanges = false): string
    {
        return $this->client()->listen($this, $includeMetadataChanges);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isCollectionGroup(): bool
    {
        return $this->collectionGroup;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $wire = [
            'path' => $this->path,
            'collectionGroup' => $this->collectionGroup,
            'filters' => array_map(fn (Filter $filter) => $filter->toWire(), $this->filters),
            'orderBy' => $this->orderBy,
            'limit' => $this->limit,
            'limitType' => $this->limitType,
            'startAt' => $this->startCursor,
            'endAt' => $this->endCursor,
        ];

        QueryRules::validate($wire);

        foreach ($this->cursorSnapshots as $edge => $snapshot) {
            $wire[$edge === 'start' ? 'startAt' : 'endAt']['values'] = $this->snapshotCursorValues($wire, $snapshot);
        }

        return $wire;
    }

    /**
     * The snapshot's value for every field the query is really ordered by,
     * ending with its document reference. Sending these (rather than having
     * the device re-read the document) keeps the cursor where the snapshot
     * was, even if the document has changed or been deleted since.
     *
     * @param  array<string, mixed>  $wire
     * @return list<mixed>
     */
    private function snapshotCursorValues(array $wire, DocumentSnapshot $snapshot): array
    {
        $missing = QueryRules::missingCursorFields($wire, fn (string $field) => Arr::has($snapshot->data() ?? [], $field));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'The cursor document [%s] has no [%s] field, which the query is ordered by.',
                $snapshot->path,
                implode(', ', $missing),
            ));
        }

        $orderBy = QueryRules::normalizedOrderBy($wire);

        foreach ($orderBy as $order) {
            if ($order['field'] !== '__name__' && $snapshot->hasPendingServerTimestamp($order['field'])) {
                throw new InvalidArgumentException(sprintf(
                    'The cursor document [%s] has a pending server timestamp in [%s], which the query is ordered by; wait for the write to commit.',
                    $snapshot->path,
                    $order['field'],
                ));
            }
        }

        return array_map(
            fn (array $order) => ValueCodec::encodeArgument($order['field'] === '__name__' ? $snapshot->reference() : $snapshot->get($order['field'])),
            $orderBy,
        );
    }

    protected function client(): FirestoreClient
    {
        return $this->client ??= app(FirestoreClient::class);
    }

    private function withLimit(int $limit, string $type): static
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('A query limit must be at least 1.');
        }

        $query = clone $this;
        $query->limit = $limit;
        $query->limitType = $type;

        return $query;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function withCursor(string $edge, array $values, bool $inclusive): static
    {
        if ($values === []) {
            throw new InvalidArgumentException('A query cursor needs at least one value or a document snapshot.');
        }

        if (count($values) === 1 && $values[0] instanceof DocumentSnapshot) {
            if (! $values[0]->exists()) {
                throw new InvalidArgumentException('A query cursor cannot use a document that does not exist.');
            }

            $cursor = ['document' => $values[0]->path, 'inclusive' => $inclusive];
            $snapshot = $values[0];
        } else {
            $cursor = ['values' => array_map(ValueCodec::encodeArgument(...), array_values($values)), 'inclusive' => $inclusive];
            $snapshot = null;
        }

        $query = clone $this;
        unset($query->cursorSnapshots[$edge]);

        if ($snapshot !== null) {
            $query->cursorSnapshots[$edge] = $snapshot;
        }

        if ($edge === 'start') {
            $query->startCursor = $cursor;
        } else {
            $query->endCursor = $cursor;
        }

        return $query;
    }
}
