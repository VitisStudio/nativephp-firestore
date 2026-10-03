<?php

namespace VitisStudio\NativeFirestore\Query;

use LogicException;
use VitisStudio\NativeFirestore\FirestoreClient;

/**
 * Up to 500 writes committed atomically: all apply or none do.
 */
class WriteBatch
{
    public const MAX_WRITES = 500;

    /** @var list<array<string, mixed>> */
    private array $writes = [];

    private bool $committed = false;

    public function __construct(private readonly FirestoreClient $client) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  bool|list<string>  $merge
     */
    public function set(DocumentReference|string $document, array $data, bool|array $merge = false): static
    {
        return $this->push(DocumentReference::setWrite($this->path($document), $data, $merge));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DocumentReference|string $document, array $data): static
    {
        return $this->push(DocumentReference::updateWrite($this->path($document), $data));
    }

    public function delete(DocumentReference|string $document): static
    {
        return $this->push(DocumentReference::deleteWrite($this->path($document)));
    }

    public function commit(): void
    {
        if ($this->committed) {
            throw new LogicException('This batch has already been committed.');
        }

        $this->committed = true;

        if ($this->writes === []) {
            return;
        }

        $this->client->driver()->commitWrites($this->writes, $this->client->waitsForServer());
    }

    public function count(): int
    {
        return count($this->writes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * @param  array<string, mixed>  $write
     */
    private function push(array $write): static
    {
        if ($this->committed) {
            throw new LogicException('Cannot add writes to a batch that has been committed.');
        }

        if (count($this->writes) >= self::MAX_WRITES) {
            throw new LogicException('A write batch can contain at most '.self::MAX_WRITES.' writes.');
        }

        $this->writes[] = $write;

        return $this;
    }

    private function path(DocumentReference|string $document): string
    {
        return $document instanceof DocumentReference ? $document->path() : (new DocumentReference($document))->path();
    }
}
