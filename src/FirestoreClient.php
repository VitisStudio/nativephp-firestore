<?php

namespace VitisStudio\NativeFirestore;

use InvalidArgumentException;
use Throwable;
use VitisStudio\NativeFirestore\Contracts\FirestoreDriver;
use VitisStudio\NativeFirestore\Exceptions\FirestoreException;
use VitisStudio\NativeFirestore\Query\CollectionReference;
use VitisStudio\NativeFirestore\Query\DocumentReference;
use VitisStudio\NativeFirestore\Query\Query;
use VitisStudio\NativeFirestore\Query\Transaction;
use VitisStudio\NativeFirestore\Query\WriteBatch;
use VitisStudio\NativeFirestore\Support\Path;

/**
 * The object behind the Firestore facade.
 */
class FirestoreClient
{
    public function __construct(
        private readonly FirestoreDriver $driver,
        private bool $waitForServer = false,
        private int $transactionMaxAttempts = 5,
    ) {}

    public function collection(string $path): CollectionReference
    {
        return new CollectionReference($path, $this);
    }

    public function doc(string $path): DocumentReference
    {
        return new DocumentReference($path, $this);
    }

    /**
     * Query every collection with this id, at any depth.
     */
    public function collectionGroup(string $collectionId): Query
    {
        return new Query(Path::collectionId($collectionId), true, $this);
    }

    /**
     * With a callback: build the batch inside it and commit when it returns.
     * Without: return an empty batch to fill and commit() yourself.
     *
     * @param  (callable(WriteBatch): mixed)|null  $callback
     */
    public function batch(?callable $callback = null): WriteBatch
    {
        $batch = new WriteBatch($this);

        if ($callback !== null) {
            $callback($batch);
            $batch->commit();
        }

        return $batch;
    }

    /**
     * Run $callback inside a native Firestore transaction and return its
     * result. The callback may run more than once: when a document it read
     * changes before the commit, Firestore retries it with fresh reads (up to
     * $maxAttempts). Keep it free of side effects other than the
     * transaction's own writes. Transactions need a connection.
     *
     * @template TResult
     *
     * @param  callable(Transaction): TResult  $callback
     * @return TResult
     */
    public function transaction(callable $callback, ?int $maxAttempts = null): mixed
    {
        $maxAttempts ??= $this->transactionMaxAttempts;

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('A transaction needs at least one attempt.');
        }

        $transactionId = $this->driver->beginTransaction($maxAttempts);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $transaction = new Transaction($this->driver, $transactionId, $attempt);

            try {
                $result = $callback($transaction);
            } catch (Throwable $exception) {
                $this->abortQuietly($transactionId);

                throw $exception;
            }

            if ($this->driver->commitTransaction($transactionId, $transaction->writes())) {
                return $result;
            }
        }

        $this->abortQuietly($transactionId);

        throw new FirestoreException(
            "Transaction did not commit after {$maxAttempts} attempts.",
            FirestoreException::ABORTED,
        );
    }

    /**
     * Listen to a document (DocumentChanged events) or a query
     * (QuerySnapshotChanged events). Returns the listener id.
     */
    public function listen(DocumentReference|Query $target, bool $includeMetadataChanges = false): string
    {
        $wire = $target instanceof DocumentReference
            ? ['type' => 'document', 'path' => $target->path()]
            : ['type' => 'query', 'query' => $target->toWire()];

        return $this->driver->listen($wire, $includeMetadataChanges);
    }

    public function stop(string $listenerId): void
    {
        $this->driver->stopListening($listenerId);
    }

    public function stopAll(): void
    {
        $this->driver->stopAllListeners();
    }

    public function enableNetwork(): void
    {
        $this->driver->enableNetwork();
    }

    public function disableNetwork(): void
    {
        $this->driver->disableNetwork();
    }

    /**
     * Block until every pending write has reached the backend.
     */
    public function waitForPendingWrites(): void
    {
        $this->driver->waitForPendingWrites();
    }

    /**
     * Toggle whether writes block until the server acknowledges them.
     */
    public function waitForServer(bool $wait = true): static
    {
        $this->waitForServer = $wait;

        return $this;
    }

    public function waitsForServer(): bool
    {
        return $this->waitForServer;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->driver->status();
    }

    public function driver(): FirestoreDriver
    {
        return $this->driver;
    }

    private function abortQuietly(string $transactionId): void
    {
        try {
            $this->driver->abortTransaction($transactionId);
        } catch (Throwable) {
            // The transaction may already be finished natively; the caller's
            // own exception is the one worth surfacing.
        }
    }
}
