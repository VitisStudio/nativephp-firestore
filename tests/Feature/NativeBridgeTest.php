<?php

use Native\Mobile\Testing\FakeBridge;
use VitisStudio\NativeFirestore\Bridge\NativeBridge;
use VitisStudio\NativeFirestore\DTO\MapValue;
use VitisStudio\NativeFirestore\Events\DocumentChanged;
use VitisStudio\NativeFirestore\Events\QuerySnapshotChanged;
use VitisStudio\NativeFirestore\Exceptions\FirebaseAuthException;
use VitisStudio\NativeFirestore\Exceptions\FirebaseException;
use VitisStudio\NativeFirestore\Exceptions\FirestoreException;
use VitisStudio\NativeFirestore\Facades\FirebaseAuth;
use VitisStudio\NativeFirestore\Facades\Firestore;
use VitisStudio\NativeFirestore\FieldValue;
use VitisStudio\NativeFirestore\Query\Transaction;
use VitisStudio\NativeFirestore\Support\ValueCodec;

// On a device nativephp_call() is a C function; in tests NativePHP's PHP
// polyfill routes it to FakeBridge, which lets us see the exact wire traffic.
require_once dirname(__DIR__, 2).'/vendor/nativephp/mobile/src/jump_bridge_functions.php';

function payloadOf(array $call): array
{
    return json_decode($call['params']['payload'], true);
}

function nativeResult(mixed $value): array
{
    return ['result' => json_encode($value, JSON_PRESERVE_ZERO_FRACTION)];
}

beforeEach(function () {
    $this->bridge = FakeBridge::enable()->respondTo('Firestore.Configure', nativeResult(['configured' => true]));
});

afterEach(fn () => FakeBridge::disable());

it('configures Firebase once before the first call, from config', function () {
    config()->set('firestore.options.project_id', 'demo-test');
    config()->set('firestore.emulator.enabled', true);
    config()->set('firestore.persistence.cache_size_bytes', 5_000_000);
    app()->forgetInstance(NativeBridge::class);
    app()->forgetInstance('firestore');
    Firestore::clearResolvedInstances();

    $this->bridge->respondTo('Firestore.GetDocument', nativeResult(['id' => 'n1', 'path' => 'notes/n1', 'exists' => false, 'data' => null]));

    Firestore::doc('notes/n1')->get();
    Firestore::doc('notes/n1')->get();

    $this->bridge->assertCalledTimes('Firestore.Configure', 1)
        ->assertCallOrder(['Firestore.Configure', 'Firestore.GetDocument', 'Firestore.GetDocument']);

    $configure = payloadOf($this->bridge->callsTo('Firestore.Configure')[0]);

    expect($configure['options']['projectId'])->toBe('demo-test')
        ->and($configure['emulator'])->toMatchArray(['enabled' => true, 'firestorePort' => 8080, 'authPort' => 9099, 'firestoreHost' => null])
        ->and($configure['persistence'])->toBe(['enabled' => true, 'cacheSizeBytes' => 5_000_000])
        ->and($configure['database'])->toBe('(default)');
});

it('sends writes as encoded wire payloads', function () {
    $this->bridge->respondTo('Firestore.Commit', nativeResult(['committed' => 1]));

    Firestore::doc('notes/n1')->set(['title' => 'Hi', 'price' => 2.0, 'seen' => FieldValue::serverTimestamp()], merge: true);

    $payload = payloadOf($this->bridge->callsTo('Firestore.Commit')[0]);

    expect($payload)->toBe([
        'writes' => [[
            'type' => 'set',
            'path' => 'notes/n1',
            'data' => ['title' => 'Hi', 'price' => 2.0, 'seen' => ['__type' => 'fieldvalue', 'op' => 'serverTimestamp']],
            'merge' => true,
        ]],
        'waitForServer' => false,
    ]);

    Firestore::waitForServer();
    Firestore::doc('notes/n1')->delete();
    expect(payloadOf($this->bridge->callsTo('Firestore.Commit')[1])['waitForServer'])->toBeTrue();
});

it('decodes query results', function () {
    $this->bridge->respondTo('Firestore.RunQuery', nativeResult([
        'documents' => [[
            'id' => 'n1', 'path' => 'notes/n1', 'exists' => true,
            'data' => ['at' => ['__type' => 'timestamp', 'seconds' => 5, 'nanoseconds' => 0]],
            'metadata' => ['fromCache' => true, 'hasPendingWrites' => false],
        ]],
        'changes' => [],
        'metadata' => ['fromCache' => true, 'hasPendingWrites' => false],
    ]));

    $snapshot = Firestore::collection('notes')->where('done', false)->limit(5)->get();

    expect($snapshot->fromCache())->toBeTrue()
        ->and($snapshot->first()->get('at')->seconds)->toBe(5)
        ->and(payloadOf($this->bridge->callsTo('Firestore.RunQuery')[0])['query']['limit'])->toBe(5);
});

it('keeps empty and list-like maps maps from native results through cursors and write-back', function () {
    // Native code tags list-like maps; untagged JSON objects are kept apart from arrays too.
    $this->bridge->respondTo('Firestore.GetDocument', ['result' => '{"id":"n1","path":"notes/n1","exists":true,'
        .'"data":{"tagged":{"__type":"map","value":{}},"numeric":{"__type":"map","value":{"0":"a","1":"b"}},'
        .'"plain":{},"plainNumeric":{"0":"a"},"list":[],"inList":[{}],"map":{"k":{}}},'
        .'"metadata":{"fromCache":false,"hasPendingWrites":false,"pendingServerTimestamps":[]}}']);
    $this->bridge->respondTo('Firestore.RunQuery', nativeResult(['documents' => [], 'changes' => [], 'metadata' => ['fromCache' => false, 'hasPendingWrites' => false]]));
    $this->bridge->respondTo('Firestore.Commit', nativeResult(['committed' => 1]));

    $snapshot = Firestore::doc('notes/n1')->get();

    expect($snapshot->get('tagged'))->toEqual(new MapValue)
        ->and($snapshot->get('numeric'))->toEqual(new MapValue(['a', 'b']))
        ->and($snapshot->get('plain'))->toEqual(new MapValue)
        ->and($snapshot->get('plainNumeric'))->toEqual(new MapValue(['a']))
        ->and($snapshot->get('list'))->toBe([])
        ->and($snapshot->get('inList'))->toEqual([new MapValue])
        ->and($snapshot->get('map.k'))->toEqual(new MapValue);

    Firestore::collection('notes')->orderBy('tagged')->orderBy('numeric')->startAfter($snapshot)->get();
    Firestore::doc('notes/copy')->set($snapshot->data());

    $query = json_decode($this->bridge->callsTo('Firestore.RunQuery')[0]['params']['payload'])->query;
    $write = json_decode($this->bridge->callsTo('Firestore.Commit')[0]['params']['payload'])->writes[0]->data;

    expect(ValueCodec::json($query->startAt->values))->toBe('[{},{"0":"a","1":"b"},{"__type":"reference","path":"notes/n1"}]')
        ->and(ValueCodec::json($write))->toBe('{"tagged":{},"numeric":{"0":"a","1":"b"},"plain":{},"plainNumeric":{"0":"a"},"list":[],"inList":[{}],"map":{"k":{}}}');
});

it('decodes event payloads whose list-like maps native code tagged', function () {
    // NativePHP hands events their payload decoded to arrays, so only the tag survives.
    $payload = json_decode('{"listenerId":"L1","path":"notes","documents":[{"id":"n1","path":"notes/n1","exists":true,'
        .'"data":{"m":{"__type":"map","value":{}},"n":{"__type":"map","value":{"0":1}},"l":[]},"metadata":{"fromCache":false,"hasPendingWrites":false}}],'
        .'"changes":[{"type":"added","document":{"id":"n1","path":"notes/n1","exists":true,"data":{"m":{"__type":"map","value":{}}},'
        .'"metadata":{"fromCache":false,"hasPendingWrites":false}},"oldIndex":-1,"newIndex":0}],"fromCache":false,"hasPendingWrites":false}', true);

    $snapshot = (new QuerySnapshotChanged(...$payload))->snapshot();
    $document = (new DocumentChanged(listenerId: 'L2', path: 'notes/n1', id: 'n1', exists: true, data: $payload['documents'][0]['data']))->snapshot();

    expect($snapshot->first()->data())->toEqual(['m' => new MapValue, 'n' => new MapValue([1]), 'l' => []])
        ->and($snapshot->docChanges()[0]->document->get('m'))->toEqual(new MapValue)
        ->and($document->data())->toEqual($snapshot->first()->data())
        ->and(ValueCodec::json(ValueCodec::encodeMap($document->data())))->toBe('{"m":{},"n":{"0":1},"l":[]}');
});

it('maps native error envelopes to typed exceptions', function () {
    $this->bridge->respondTo('Firestore.GetDocument', ['status' => 'error', 'code' => 'permission-denied', 'message' => 'Missing or insufficient permissions.']);
    $this->bridge->respondTo('FirebaseAuth.SignInWithEmailAndPassword', ['status' => 'error', 'code' => 'invalid-credential', 'message' => 'Bad credentials']);

    expect(fn () => Firestore::doc('a/b')->get())
        ->toThrow(fn (FirestoreException $e) => expect($e->isPermissionDenied())->toBeTrue())
        ->and(fn () => FirebaseAuth::signInWithEmailAndPassword('a@b.co', 'x'))
        ->toThrow(fn (FirebaseAuthException $e) => expect($e->errorCode())->toBe('invalid-credential'));
});

it('explains when the plugin is not in the running binary', function () {
    // An unscripted method returns null, as an unregistered function does natively.
    expect(fn () => Firestore::collection('a')->count())
        ->toThrow(fn (FirebaseException $e) => expect($e->isBridgeUnavailable())->toBeTrue()
            ->and($e->getMessage())->toContain('native:run'));

    $this->bridge->respondTo('Firestore.Count', ['status' => 'error', 'code' => 'NO_DEVICE', 'message' => 'No device connected.']);
    expect(fn () => Firestore::collection('a')->count())
        ->toThrow(fn (FirebaseException $e) => expect($e->isBridgeUnavailable())->toBeTrue());
});

it('surfaces a not-configured Firebase app clearly and retries configuration later', function () {
    $this->bridge->respondTo('Firestore.Configure', ['status' => 'error', 'code' => 'not-configured', 'message' => 'No GoogleService-Info.plist and no FIREBASE_PROJECT_ID.']);

    expect(fn () => Firestore::doc('a/b')->get())
        ->toThrow(fn (FirestoreException $e) => expect($e->isNotConfigured())->toBeTrue());

    $this->bridge->respondTo('Firestore.Configure', nativeResult(['configured' => true]));
    $this->bridge->respondTo('Firestore.GetDocument', nativeResult(['path' => 'a/b', 'exists' => false]));

    expect(Firestore::doc('a/b')->get()->exists())->toBeFalse();
    $this->bridge->assertCalledTimes('Firestore.Configure', 2);
});

it('drives the native transaction protocol, re-running the callback on retry', function () {
    $commits = 0;
    $this->bridge
        ->respondTo('Firestore.TransactionBegin', nativeResult(['transactionId' => 'tx-1']))
        ->respondTo('Firestore.TransactionGet', nativeResult(['path' => 'c/1', 'exists' => true, 'data' => ['n' => 1]]))
        ->respondTo('Firestore.TransactionCommit', function () use (&$commits) {
            return nativeResult(['outcome' => ++$commits === 1 ? 'retry' : 'committed']);
        });

    $runs = 0;
    $result = Firestore::transaction(function (Transaction $transaction) use (&$runs) {
        $runs++;
        $n = $transaction->get('c/1')->get('n');
        $transaction->update('c/1', ['n' => $n + 1]);

        return 'done';
    });

    expect($result)->toBe('done')->and($runs)->toBe(2);

    $commit = payloadOf($this->bridge->callsTo('Firestore.TransactionCommit')[1]);
    expect($commit['transactionId'])->toBe('tx-1')
        ->and($commit['writes'][0])->toMatchArray(['type' => 'update', 'path' => 'c/1', 'data' => ['n' => 2]]);

    $this->bridge->assertNotCalled('Firestore.TransactionAbort');
});

it('aborts the native transaction when the callback throws', function () {
    $this->bridge
        ->respondTo('Firestore.TransactionBegin', nativeResult(['transactionId' => 'tx-2']))
        ->respondTo('Firestore.TransactionAbort', nativeResult(['aborted' => true]));

    expect(fn () => Firestore::transaction(fn () => throw new DomainException('stop')))
        ->toThrow(DomainException::class);

    $this->bridge->assertCalled('Firestore.TransactionAbort', fn (array $params) => json_decode($params['payload'], true)['transactionId'] === 'tx-2')
        ->assertNotCalled('Firestore.TransactionCommit');
});

it('starts and stops listeners', function () {
    $this->bridge
        ->respondTo('Firestore.Listen', nativeResult(['listenerId' => 'L1']))
        ->respondTo('Firestore.StopListening', nativeResult([]));

    $id = Firestore::collection('notes')->orderBy('n')->listen(includeMetadataChanges: true);
    Firestore::stop($id);

    $listen = payloadOf($this->bridge->callsTo('Firestore.Listen')[0]);

    expect($id)->toBe('L1')
        ->and($listen['includeMetadataChanges'])->toBeTrue()
        ->and($listen['target']['type'])->toBe('query')
        ->and($listen['target']['query']['orderBy'])->toBe([['field' => 'n', 'direction' => 'asc']]);
});

it('returns auth users from the bridge', function () {
    $this->bridge
        ->respondTo('FirebaseAuth.CurrentUser', nativeResult(['user' => ['uid' => 'u1', 'email' => 'a@b.co', 'emailVerified' => true]]))
        ->respondTo('FirebaseAuth.GetIdToken', nativeResult(['token' => 'jwt']));

    expect(FirebaseAuth::currentUser()->email)->toBe('a@b.co')
        ->and(FirebaseAuth::getIdToken(true))->toBe('jwt')
        ->and(payloadOf($this->bridge->callsTo('FirebaseAuth.GetIdToken')[0]))->toBe(['forceRefresh' => true]);
});
