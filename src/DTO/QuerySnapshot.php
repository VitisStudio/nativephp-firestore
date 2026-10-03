<?php

namespace VitisStudio\NativeFirestore\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, DocumentSnapshot>
 */
final class QuerySnapshot implements Countable, IteratorAggregate
{
    /**
     * @param  list<DocumentSnapshot>  $documents
     * @param  list<DocumentChange>  $changes
     */
    public function __construct(
        public readonly array $documents,
        public readonly array $changes = [],
        public readonly SnapshotMetadata $metadata = new SnapshotMetadata,
    ) {}

    /**
     * Build a snapshot from its wire form. Accepts both the bridge shape
     * (`metadata` nested) and the flat QuerySnapshotChanged event payload
     * (`fromCache` / `hasPendingWrites` at the top level).
     *
     * @param  array{documents?: list<array<string, mixed>>, changes?: list<array<string, mixed>>, metadata?: array<string, bool>, fromCache?: bool, hasPendingWrites?: bool}  $wire
     */
    public static function fromArray(array $wire): self
    {
        $metadata = $wire['metadata'] ?? [
            'fromCache' => $wire['fromCache'] ?? false,
            'hasPendingWrites' => $wire['hasPendingWrites'] ?? false,
        ];

        return new self(
            documents: array_values(array_map(DocumentSnapshot::fromArray(...), $wire['documents'] ?? [])),
            changes: array_values(array_map(DocumentChange::fromArray(...), $wire['changes'] ?? [])),
            metadata: SnapshotMetadata::fromArray($metadata),
        );
    }

    /**
     * @return list<DocumentSnapshot>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    /**
     * @return list<DocumentChange>
     */
    public function docChanges(): array
    {
        return $this->changes;
    }

    public function first(): ?DocumentSnapshot
    {
        return $this->documents[0] ?? null;
    }

    public function size(): int
    {
        return count($this->documents);
    }

    public function isEmpty(): bool
    {
        return $this->documents === [];
    }

    public function fromCache(): bool
    {
        return $this->metadata->fromCache;
    }

    public function hasPendingWrites(): bool
    {
        return $this->metadata->hasPendingWrites;
    }

    /**
     * Each document as `['id' => ..., ...data]`.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (DocumentSnapshot $document) => $document->toArray(), $this->documents);
    }

    public function count(): int
    {
        return $this->size();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->documents);
    }
}
