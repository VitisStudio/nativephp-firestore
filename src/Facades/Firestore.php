<?php

namespace VitisStudio\NativeFirestore\Facades;

use Illuminate\Support\Facades\Facade;
use VitisStudio\NativeFirestore\FirestoreClient;
use VitisStudio\NativeFirestore\Testing\FakeFirestore;

/**
 * @method static \VitisStudio\NativeFirestore\Query\CollectionReference collection(string $path)
 * @method static \VitisStudio\NativeFirestore\Query\DocumentReference doc(string $path)
 * @method static \VitisStudio\NativeFirestore\Query\Query collectionGroup(string $collectionId)
 * @method static \VitisStudio\NativeFirestore\Query\WriteBatch batch(?callable $callback = null)
 * @method static mixed transaction(callable $callback, ?int $maxAttempts = null)
 * @method static string listen(\VitisStudio\NativeFirestore\Query\DocumentReference|\VitisStudio\NativeFirestore\Query\Query $target, bool $includeMetadataChanges = false)
 * @method static void stop(string $listenerId)
 * @method static void stopAll()
 * @method static void enableNetwork()
 * @method static void disableNetwork()
 * @method static void waitForPendingWrites()
 * @method static \VitisStudio\NativeFirestore\FirestoreClient waitForServer(bool $wait = true)
 * @method static bool waitsForServer()
 * @method static array status()
 * @method static \VitisStudio\NativeFirestore\Contracts\FirestoreDriver driver()
 *
 * @see FirestoreClient
 */
class Firestore extends Facade
{
    /**
     * Swap in an in-memory Firestore for tests and return it for seeding and
     * assertions.
     */
    public static function fake(): FakeFirestore
    {
        $fake = new FakeFirestore;

        static::swap(new FirestoreClient(
            $fake,
            (bool) config('firestore.wait_for_server', false),
            (int) config('firestore.transaction_max_attempts', 5),
        ));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'firestore';
    }
}
