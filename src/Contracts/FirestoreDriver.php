<?php

namespace VitisStudio\NativeFirestore\Contracts;

/**
 * The wire-level operations behind the Firestore API. The native driver
 * forwards them over the NativePHP bridge; the fake runs them in memory.
 * Every value crossing this boundary is in ValueCodec's wire format.
 */
interface FirestoreDriver
{
    /**
     * @return array<string, mixed> document snapshot wire array
     */
    public function getDocument(string $path, string $source): array;

    /**
     * Apply writes atomically (one write or a batch).
     *
     * @param  list<array<string, mixed>>  $writes
     */
    public function commitWrites(array $writes, bool $waitForServer): void;

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> query snapshot wire array
     */
    public function runQuery(array $query, string $source): array;

    /**
     * @param  array<string, mixed>  $query
     */
    public function count(array $query): int;

    public function beginTransaction(int $maxAttempts): string;

    /**
     * @return array<string, mixed>
     */
    public function transactionGet(string $transactionId, string $path): array;

    /**
     * Hand the attempt's writes over. True when the transaction committed,
     * false when Firestore wants the callback run again (contention).
     *
     * @param  list<array<string, mixed>>  $writes
     */
    public function commitTransaction(string $transactionId, array $writes): bool;

    public function abortTransaction(string $transactionId): void;

    /**
     * @param  array{type: 'document', path: string}|array{type: 'query', query: array<string, mixed>}  $target
     */
    public function listen(array $target, bool $includeMetadataChanges): string;

    public function stopListening(string $listenerId): void;

    public function stopAllListeners(): void;

    public function enableNetwork(): void;

    public function disableNetwork(): void;

    public function waitForPendingWrites(): void;

    /**
     * @return array<string, mixed>
     */
    public function status(): array;
}
