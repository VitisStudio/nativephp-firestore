<?php

namespace VitisStudio\NativeFirestore\Query;

use LogicException;
use VitisStudio\NativeFirestore\Contracts\FirestoreDriver;
use VitisStudio\NativeFirestore\DTO\DocumentSnapshot;

/**
 * The handle passed to Firestore::transaction() callbacks. Reads go straight
 * to the native transaction; writes are buffered and handed over in one go
 * when the callback returns. As in the SDKs, every read must happen before
 * the first write.
 */
class Transaction
{
    /** @var list<array<string, mixed>> */
    private array $writes = [];

    public function __construct(
        private readonly FirestoreDriver $driver,
        private readonly string $transactionId,
        public readonly int $attempt = 1,
    ) {}

    public function get(DocumentReference|string $document): DocumentSnapshot
    {
        if ($this->writes !== []) {
            throw new LogicException('Firestore transactions require all reads to happen before any writes.');
        }

        return DocumentSnapshot::fromArray($this->driver->transactionGet($this->transactionId, $this->path($document)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  bool|list<string>  $merge
     */
    public function set(DocumentReference|string $document, array $data, bool|array $merge = false): static
    {
        $this->writes[] = DocumentReference::setWrite($this->path($document), $data, $merge);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DocumentReference|string $document, array $data): static
    {
        $this->writes[] = DocumentReference::updateWrite($this->path($document), $data);

        return $this;
    }

    public function delete(DocumentReference|string $document): static
    {
        $this->writes[] = DocumentReference::deleteWrite($this->path($document));

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    private function path(DocumentReference|string $document): string
    {
        return $document instanceof DocumentReference ? $document->path() : (new DocumentReference($document))->path();
    }
}
