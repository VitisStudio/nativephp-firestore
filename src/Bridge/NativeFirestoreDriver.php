<?php

namespace VitisStudio\NativeFirestore\Bridge;

use VitisStudio\NativeFirestore\Contracts\FirestoreDriver;

class NativeFirestoreDriver implements FirestoreDriver
{
    public function __construct(private readonly NativeBridge $bridge) {}

    public function getDocument(string $path, string $source): array
    {
        return (array) $this->bridge->call('Firestore.GetDocument', ['path' => $path, 'source' => $source]);
    }

    public function commitWrites(array $writes, bool $waitForServer): void
    {
        $this->bridge->call('Firestore.Commit', ['writes' => $writes, 'waitForServer' => $waitForServer]);
    }

    public function runQuery(array $query, string $source): array
    {
        return (array) $this->bridge->call('Firestore.RunQuery', ['query' => $query, 'source' => $source]);
    }

    public function count(array $query): int
    {
        return (int) ($this->bridge->call('Firestore.Count', ['query' => $query])['count'] ?? 0);
    }

    public function beginTransaction(int $maxAttempts): string
    {
        return (string) $this->bridge->call('Firestore.TransactionBegin', ['maxAttempts' => $maxAttempts])['transactionId'];
    }

    public function transactionGet(string $transactionId, string $path): array
    {
        return (array) $this->bridge->call('Firestore.TransactionGet', ['transactionId' => $transactionId, 'path' => $path]);
    }

    public function commitTransaction(string $transactionId, array $writes): bool
    {
        $result = (array) $this->bridge->call('Firestore.TransactionCommit', ['transactionId' => $transactionId, 'writes' => $writes]);

        return ($result['outcome'] ?? null) === 'committed';
    }

    public function abortTransaction(string $transactionId): void
    {
        $this->bridge->call('Firestore.TransactionAbort', ['transactionId' => $transactionId]);
    }

    public function listen(array $target, bool $includeMetadataChanges): string
    {
        return (string) $this->bridge->call('Firestore.Listen', [
            'target' => $target,
            'includeMetadataChanges' => $includeMetadataChanges,
        ])['listenerId'];
    }

    public function stopListening(string $listenerId): void
    {
        $this->bridge->call('Firestore.StopListening', ['listenerId' => $listenerId]);
    }

    public function stopAllListeners(): void
    {
        $this->bridge->call('Firestore.StopAllListeners');
    }

    public function enableNetwork(): void
    {
        $this->bridge->call('Firestore.EnableNetwork');
    }

    public function disableNetwork(): void
    {
        $this->bridge->call('Firestore.DisableNetwork');
    }

    public function waitForPendingWrites(): void
    {
        $this->bridge->call('Firestore.WaitForPendingWrites');
    }

    public function status(): array
    {
        return (array) $this->bridge->call('Firestore.Status');
    }
}
