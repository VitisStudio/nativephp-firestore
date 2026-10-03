# NativePHP Firestore

Cloud Firestore and Firebase Authentication for [NativePHP Mobile](https://nativephp.com) v4, backed by the **native Firebase SDKs** (FirebaseFirestore + FirebaseAuth on iOS via Swift Package Manager, `firebase-firestore` + `firebase-auth` on Android via the Firebase BoM).

You get the real SDKs' behaviour on-device: offline persistence, realtime listeners, latency-compensated writes, transactions and security rules that see the signed-in user. The API is PHP:

```php
use VitisStudio\NativeFirestore\Facades\Firestore;
use VitisStudio\NativeFirestore\FieldValue;

$ref = Firestore::collection('notes')->add([
    'text' => 'Buy milk',
    'done' => false,
    'createdAt' => FieldValue::serverTimestamp(),
]);

$open = Firestore::collection('notes')
    ->where('done', false)
    ->orderBy('createdAt', 'desc')
    ->limit(20)
    ->get();

foreach ($open as $note) {
    echo $note->id().': '.$note->get('text');
}
```

The package is app-agnostic: it ships no Firebase project configuration. Each app supplies its own `GoogleService-Info.plist` / `google-services.json`, or explicit options.

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Firestore API](#firestore-api)
- [Realtime listeners](#realtime-listeners)
- [Batches and transactions](#batches-and-transactions)
- [Firebase Auth](#firebase-auth)
- [Errors](#errors)
- [Testing with fakes](#testing-with-fakes)
- [JavaScript](#javascript)
- [Limitations](#limitations)

## Requirements

- NativePHP Mobile `^3.0|^4.0` (developed against v4.6), PHP 8.2+, Laravel 11–13.
- iOS 15+ deployment target (NativePHP apps target 18.x), Xcode 16.2+ (Firebase iOS SDK 12).
- Android minSdk 26.
- **Builds with `php artisan native:run`, not Jump.** Jump runs a prebuilt shell app that only contains NativePHP's own native code, so a plugin with custom Swift/Kotlin (like this one) isn't in it. Under Jump every call fails with a `bridge-unavailable` error explaining this. Use `native:run` builds on a simulator, emulator or device.

## Installation

```bash
composer require vitisstudio/nativephp-firestore
php artisan native:plugin:register vitisstudio/nativephp-firestore
php artisan vendor:publish --tag=firestore-config   # optional
```

`native:plugin:register` adds the service provider to your `NativeServiceProvider::plugins()`. NativePHP requires that step before it compiles a plugin into your build. The next `php artisan native:run` adds the Firebase packages to the native projects.

## Configuration

Firebase is initialised **once per app launch**, on the first Firestore or Auth call. The native code chooses the configuration in this order:

1. **An existing FirebaseApp.** If your app or another plugin (for example NativePHP's push-notification Firebase plugin) has already configured Firebase, this plugin reuses it.
2. **Explicit options** from `config/firestore.php` / `.env`, when `FIREBASE_PROJECT_ID` is set.
3. **Your config file**: `GoogleService-Info.plist` (iOS) or `google-services.json` (Android).
4. Otherwise the call fails with a `not-configured` error and a message saying what to add. The app keeps running.

### Option A: config files from the Firebase console

Put `GoogleService-Info.plist` and/or `google-services.json` in your project root. If they live elsewhere, point `FIREBASE_IOS_CONFIG_PATH` / `FIREBASE_ANDROID_CONFIG_PATH` at them. The plugin's `copy_assets` build hook copies them into the native projects as `NativeFirestore-GoogleService-Info.plist` (iOS bundle) and `assets/nativephp-firestore/google-services.json` (Android). The plugin-specific names can't collide with files another Firebase plugin installs. Android doesn't need the Google Services Gradle plugin, because the JSON is read at runtime.

### Option B: explicit options

```dotenv
FIREBASE_PROJECT_ID=my-project
FIREBASE_API_KEY=AIza...
FIREBASE_SENDER_ID=1234567890
FIREBASE_IOS_APP_ID=1:1234567890:ios:abc123
FIREBASE_ANDROID_APP_ID=1:1234567890:android:abc123
FIREBASE_STORAGE_BUCKET=my-project.appspot.com   # optional
```

`FIREBASE_APP_ID` works as a fallback for both platform app ids.

### Option C: Emulator Suite with a demo project (no Firebase account)

```bash
firebase emulators:start --only firestore,auth --project demo-myapp
```

```dotenv
FIREBASE_PROJECT_ID=demo-myapp
FIREBASE_EMULATOR=true
```

A project id that starts with `demo-` needs no other options; the native code fills in placeholder app ids and API keys. When the emulator host is left empty, each platform uses the address that reaches your machine: **`127.0.0.1` from the iOS simulator** and **`10.0.2.2` from the Android emulator**. On a physical device, set your machine's LAN IP:

```dotenv
FIRESTORE_EMULATOR_HOST=192.168.1.20
FIRESTORE_EMULATOR_PORT=8080
FIREBASE_AUTH_EMULATOR_HOST=192.168.1.20
FIREBASE_AUTH_EMULATOR_PORT=9099
```

### All keys

| Config (`firestore.*`) | Env | Default |
|---|---|---|
| `ios.config_path` | `FIREBASE_IOS_CONFIG_PATH` | `base_path('GoogleService-Info.plist')` |
| `android.config_path` | `FIREBASE_ANDROID_CONFIG_PATH` | `base_path('google-services.json')` |
| `options.project_id` | `FIREBASE_PROJECT_ID` | – |
| `options.api_key` | `FIREBASE_API_KEY` | – |
| `options.sender_id` | `FIREBASE_SENDER_ID` | derived from the app id |
| `options.storage_bucket` | `FIREBASE_STORAGE_BUCKET` | – |
| `options.ios_app_id` | `FIREBASE_IOS_APP_ID` (or `FIREBASE_APP_ID`) | – |
| `options.android_app_id` | `FIREBASE_ANDROID_APP_ID` (or `FIREBASE_APP_ID`) | – |
| `database` | `FIRESTORE_DATABASE` | `(default)` |
| `emulator.enabled` | `FIREBASE_EMULATOR` | `false` |
| `emulator.firestore.host` / `.port` | `FIRESTORE_EMULATOR_HOST` / `_PORT` | platform default / `8080` |
| `emulator.auth.host` / `.port` | `FIREBASE_AUTH_EMULATOR_HOST` / `_PORT` | platform default / `9099` |
| `persistence.enabled` | `FIRESTORE_PERSISTENCE` | `true` |
| `persistence.cache_size_bytes` | `FIRESTORE_CACHE_SIZE_BYTES` | 100 MB (`-1` = unlimited, minimum 1 MB) |
| `timeout` | `FIRESTORE_TIMEOUT` | `15` seconds per blocking call |
| `wait_for_server` | `FIRESTORE_WAIT_FOR_SERVER` | `false` (see [Writes](#writes)) |
| `server_timestamps` | `FIRESTORE_SERVER_TIMESTAMPS` | `none` (`estimate` / `previous`) |
| `transaction_max_attempts` | `FIRESTORE_TRANSACTION_MAX_ATTEMPTS` | `5` |

> NativePHP bundles your `.env` into the app. If you use `cleanup_env_keys` in `config/nativephp.php`, don't strip the `FIREBASE_*` / `FIRESTORE_*` keys. Firebase API keys identify the project; they aren't secrets. Security rules are what protect your data.

Configuration is read once per launch. Settings such as persistence, emulator and database are applied before Firestore is first used, so a change only takes effect after an app restart.

## Firestore API

### References

```php
Firestore::collection('users');                 // CollectionReference
Firestore::doc('users/ada');                    // DocumentReference
Firestore::collection('users')->doc('ada');     // same thing
Firestore::collection('users')->doc();          // new auto-id (nothing written yet)
Firestore::doc('users/ada')->collection('notes'); // subcollection
Firestore::collectionGroup('notes');            // every "notes" collection, at any depth
```

### Reading

```php
$snapshot = Firestore::doc('users/ada')->get();          // DocumentSnapshot
$snapshot->exists();  $snapshot->id();  $snapshot->data();
$snapshot->get('address.city', 'Unknown');                // dotted paths
$snapshot->fromCache(); $snapshot->hasPendingWrites();

use VitisStudio\NativeFirestore\Query\Source;
Firestore::doc('users/ada')->get(Source::Cache);          // Default | Server | Cache
```

### Queries

Queries are immutable: each builder method returns a new query, so a base query can be reused.

```php
use VitisStudio\NativeFirestore\Query\Filter;
use VitisStudio\NativeFirestore\Query\FieldPath;

$query = Firestore::collection('tasks')
    ->where('done', false)                      // == shorthand
    ->where('priority', '>=', 2)                // == != < <= > >=
    ->whereIn('owner', ['ada', 'grace'])        // in / not-in (max 30 values)
    ->whereArrayContains('tags', 'home')        // array-contains
    ->whereArrayContainsAny('tags', ['a', 'b']) // array-contains-any
    ->where(Filter::or(
        Filter::where('status', '==', 'open'),
        Filter::where('pinned', '==', true),
    ))
    ->orderBy('priority', 'desc')
    ->limit(25);                                // or ->limitToLast(25) (needs orderBy)

$snapshot = $query->get();      // QuerySnapshot (Countable, iterable)
$snapshot->documents(); $snapshot->size(); $snapshot->first(); $snapshot->toArray();
$query->first();                // ?DocumentSnapshot
$query->count();                // server-side count() aggregation

// Cursors take values (one per orderBy) or a DocumentSnapshot
$page2 = $query->startAfter($snapshot->documents()[24])->get();
Firestore::collection('tasks')->orderBy('priority')->startAt(2)->endBefore(5)->get();

// Document id
Firestore::collection('tasks')->where(FieldPath::documentId(), 'in', ['t1', 't2']);
```

Firestore's own rules apply: inequality filters need composite indexes once they're combined with other fields (the error message contains the link that creates the index), and `not-in` can't be combined with `!=`, `in`, `array-contains-any` or OR filters.

### Writes

```php
$ref = Firestore::doc('users/ada');

$ref->set(['name' => 'Ada', 'age' => 36]);                // create / overwrite
$ref->set(['age' => 37], merge: true);                     // deep merge
$ref->set($data, merge: ['name', 'address.city']);         // merge only these fields
$ref->update(['age' => 38, 'address.city' => 'London']);   // fails with not-found if missing
$ref->delete();

Firestore::collection('users')->add(['name' => 'Grace']); // auto id, returns the DocumentReference
```

**Writes don't wait for the server by default.** They're applied to the local cache straight away: listeners fire, reads see them, and they work offline. The SDK syncs them in the background. If the backend later rejects one (for example `permission-denied`), Firestore rolls the cache back and the plugin dispatches a `WriteFailed` event. To make writes block until the server acknowledges them, set `FIRESTORE_WAIT_FOR_SERVER=true` or call `Firestore::waitForServer()` at runtime. Errors then throw, and offline writes time out after `timeout` seconds. `Firestore::waitForPendingWrites()` blocks until everything queued so far has synced.

### Values

| PHP | Firestore |
|---|---|
| `null`, `bool`, `int`, `float`, `string` | same (`10.0` stays a double) |
| list arrays / associative arrays | array / map (`[]` is an empty array; use `new stdClass` for an empty map) |
| `DTO\Timestamp`, any `DateTimeInterface` | timestamp (read back as `Timestamp`; `->toDateTime()` gives Carbon) |
| `DTO\GeoPoint` | geopoint |
| `Query\DocumentReference` | reference (read back as a live `DocumentReference`) |
| `DTO\Bytes` | bytes |
| `NAN`, `INF` | NaN / Infinity |

`FieldValue::serverTimestamp()`, `FieldValue::increment(1)`, `FieldValue::arrayUnion(...)`, `FieldValue::arrayRemove(...)` and `FieldValue::delete()` (in `update()` or a merge `set()` only) work anywhere a value goes. The field name `__type` is reserved by the plugin's wire format.

## Realtime listeners

`listen()` returns a listener id. Snapshots arrive as events: an initial snapshot first, then one per change.

| Event | Payload (constructor parameters) |
|---|---|
| `Events\QuerySnapshotChanged` | `listenerId`, `path`, `documents`, `changes`, `fromCache`, `hasPendingWrites` |
| `Events\DocumentChanged` | `listenerId`, `path`, `id`, `exists`, `data`, `fromCache`, `hasPendingWrites` |
| `Events\ListenerFailed` | `listenerId`, `code`, `message` (the listener is already detached) |
| `Events\WriteFailed` | `paths`, `code`, `message` |
| `Events\AuthStateChanged` | `user` (array or `null`) |

In a NativeComponent, handle them with `#[On]`. Parameters bind to payload keys by name:

```php
use Native\Mobile\Attributes\On;
use VitisStudio\NativeFirestore\DTO\QuerySnapshot;
use VitisStudio\NativeFirestore\Events\QuerySnapshotChanged;

public ?string $listenerId = null;
public array $notes = [];

public function mount(): void
{
    $this->listenerId = Firestore::collection('notes')->orderBy('createdAt', 'desc')->listen();
    $id = $this->listenerId;
    $this->registerCleanup(fn () => Firestore::stop($id));   // stop when leaving the screen
}

#[On(QuerySnapshotChanged::class)]
public function notesChanged(string $listenerId, array $documents, array $changes, bool $fromCache): void
{
    if ($listenerId !== $this->listenerId) {
        return; // events are broadcast; filter by your own listener id
    }

    $snapshot = QuerySnapshot::fromArray(compact('documents', 'changes'));
    $this->notes = $snapshot->toArray();          // DTOs decode Timestamps, references, …
    foreach ($snapshot->docChanges() as $change) { /* added | modified | removed, oldIndex, newIndex */ }
}
```

The events also implement NativePHP's `BroadcastsGlobally`, so `Event::listen(QuerySnapshotChanged::class, fn ($e) => $e->snapshot())` works anywhere in the app. Payload values are wire-encoded; use `->snapshot()` / `QuerySnapshot::fromArray()` / `DocumentSnapshot::fromArray()` to get decoded values. In web-view (Livewire) apps the same events arrive as `native:` events.

`listen(includeMetadataChanges: true)` also fires when only `fromCache` / `hasPendingWrites` change. Stop listeners with `Firestore::stop($id)` or `Firestore::stopAll()`.

## Batches and transactions

```php
use VitisStudio\NativeFirestore\Query\WriteBatch;

Firestore::batch(function (WriteBatch $batch) {
    $batch->set('cities/la', ['name' => 'Los Angeles'])
          ->update(Firestore::doc('cities/sf'), ['population' => FieldValue::increment(1)])
          ->delete('cities/old');
});                                          // committed atomically (max 500 writes)

$batch = Firestore::batch();                 // or fluently
$batch->set('a/1', ['n' => 1]);
$batch->commit();
```

```php
use VitisStudio\NativeFirestore\Query\Transaction;

$newBalance = Firestore::transaction(function (Transaction $tx) {
    $account = $tx->get('accounts/ada');                 // reads first …
    $balance = $account->get('balance') - 10;
    $tx->update('accounts/ada', ['balance' => $balance]); // … then writes
    return $balance;
});
```

### Transaction semantics

These are **real Firestore transactions**, not optimistic emulation. Each one is a native transaction session that PHP drives step by step:

1. `transaction()` starts the SDK's `runTransaction`. The SDK runs its transaction function on a background thread, and that function waits for commands from PHP.
2. Each `$tx->get()` is executed inside the native transaction, with the SDK's own read tracking.
3. When your callback returns, its buffered writes go to the native transaction, and the SDK commits them atomically.
4. If a document you read changed before the commit, Firestore retries: it runs the transaction function again, the plugin reports `retry`, and **your callback runs again** with fresh reads, up to `maxAttempts` (default 5). After the last attempt it throws `FirestoreException` with code `aborted`.
5. If your callback throws, the native transaction is aborted, nothing is written, and your exception is rethrown.

Rules that follow from this, all the same as in the SDKs:

- Every `get()` must come before the first write. A read after a write throws `LogicException`.
- Your callback may run more than once, so keep side effects out of it, apart from the transaction's own writes.
- Transactions need a connection; offline they fail with `unavailable`. Use batches or plain writes for offline-friendly updates.
- `$tx->attempt` says which attempt is running (starts at 1).

## Firebase Auth

```php
use VitisStudio\NativeFirestore\Facades\FirebaseAuth;

$user = FirebaseAuth::createUser('ada@example.com', 'secret123');   // signs in too
$user = FirebaseAuth::signInWithEmailAndPassword('ada@example.com', 'secret123');
$user = FirebaseAuth::signInAnonymously();
FirebaseAuth::currentUser();          // ?DTO\User (uid, email, displayName, photoUrl, emailVerified, isAnonymous, …)
FirebaseAuth::check();                // bool
FirebaseAuth::getIdToken();           // JWT for your own backend; getIdToken(forceRefresh: true)
FirebaseAuth::sendPasswordResetEmail('ada@example.com');
FirebaseAuth::signOut();

FirebaseAuth::listen();               // AuthStateChanged now, then on every sign-in / sign-out
FirebaseAuth::stopListening();
```

Auth and Firestore share the same native FirebaseApp, so security rules see `request.auth` for the signed-in user. Sessions persist across launches; that's the SDK's behaviour.

## Errors

Every failure throws a subclass of `Exceptions\FirebaseException`, with the Firebase code available from `errorCode()`:

- `FirestoreException`: `permission-denied`, `not-found`, `unavailable`, `aborted`, `failed-precondition` (often a missing index), `deadline-exceeded`, `invalid-argument`, `unauthenticated`, … Helpers: `isPermissionDenied()`, `isNotFound()`, `isUnavailable()`, `isAborted()`, `isDeadlineExceeded()`.
- `FirebaseAuthException`: `invalid-email`, `invalid-credential`, `email-already-in-use`, `weak-password`, `user-disabled`, `too-many-requests`, `network-request-failed`, …
- Plugin codes: `not-configured` (no usable Firebase configuration; the message says what to add) and `bridge-unavailable` (not running in a `native:run` build that includes the plugin, such as Jump or a plain `php artisan serve`).

```php
try {
    Firestore::doc('admin/settings')->get();
} catch (FirestoreException $e) {
    if ($e->isPermissionDenied()) { /* … */ }
}
```

The plugin never crashes the app when Firebase isn't configured. Every call returns a `not-configured` error and the reason is logged (`[NativeFirestore] …` in the device log, visible with `php artisan native:tail`).

## Testing with fakes

`Firestore::fake()` and `FirebaseAuth::fake()` swap in in-memory implementations of the same API, so unit tests run without Firebase, a device or the bridge:

```php
use VitisStudio\NativeFirestore\Facades\Firestore;
use VitisStudio\NativeFirestore\Facades\FirebaseAuth;

it('completes a task', function () {
    $firestore = Firestore::fake();
    $firestore->seed('tasks/t1', ['title' => 'Write tests', 'done' => false]);

    app(CompleteTask::class)('t1');

    $firestore->assertExists('tasks/t1', ['done' => true]);
});
```

The fake implements Firestore's semantics, not just storage:

- queries: every operator, OR/AND filters, Firestore's cross-type ordering, implicit ordering by the inequality field, documents missing an `orderBy` field are excluded, cursors, `limitToLast`, collection groups, `count()`
- all `FieldValue`s (`serverTimestamp()` uses Carbon's test clock)
- `set` / merge / `mergeFields` / dotted `update`, and atomic batches that roll back on failure
- transactions with real conflict detection: if a document you read changes before commit, the callback re-runs, and after `maxAttempts` you get `aborted`
- listeners: an initial snapshot plus incremental `docChanges` dispatched through Laravel's event dispatcher, so `Event::fake()` / `Event::assertDispatched()` work

Fake helpers: `seed()`, `seedCollection()`, `document()`, `documents()`, `failNext('permission-denied')`, `commits()`, `emittedEvents()`, `activeListeners()`, `assertExists()`, `assertMissing()`, `assertCount()`, `assertListening()`, `assertNotListening()`. `Firestore::disableNetwork()` makes server reads, `count()` and transactions fail with `unavailable`.

To drive listener events into a NativeComponent test, replay what the fake emitted:

```php
use Native\Mobile\Testing\Native;

$firestore = Firestore::fake();
$screen = Native::test(NotesScreen::class);

foreach ($firestore->emittedEvents() as [$event, $payload]) {
    $screen->emitNative($event, $payload);
}

$screen->assertSee('Buy milk');
```

`FirebaseAuth::fake()` supports `addUser()`, `actingAs()`, `assertSignedIn()`, `assertSignedOut()`, `assertPasswordResetSent()`, and uses the SDK error codes (with email-enumeration protection, an unknown email and a wrong password both give `invalid-credential`).

## JavaScript

Web-view apps can call the bridge from JavaScript via `resources/js/firestore.js` (`firestore.*` and `firebaseAuth.*`, one function per bridge function). Values use the same tagged wire format. The PHP API is the recommended interface.

## Example

`examples/` contains a small generic notes screen: a realtime list with offline indicator and add / toggle / delete. It comes with an `.env` template covering all three configuration options, example security rules, route registration and a Pest test that uses the fakes.

## Limitations

- **Jump isn't supported**, because custom native code needs a `native:run` build (see Requirements).
- **Blocking calls.** PHP waits for each SDK call, up to `timeout` seconds. Reads served from cache return immediately. Server reads, `count()`, transactions and awaited writes depend on the network.
- **Settings are per launch.** Persistence, emulator and database settings are applied the first time Firestore is used. If another component already started Firestore in the same process, Android reports `failed-precondition`. On iOS the SDK raises an exception, so don't touch Firestore natively before this plugin configures it.
- **iOS SDK preconditions.** The iOS SDK reports misuse with Objective-C exceptions, which can't be caught. The plugin validates paths, field names, sentinel placement and query shapes first, and returns those as normal `invalid-argument` errors. A query shape the plugin doesn't recognise could still abort on iOS where Android would throw.
- **Not implemented:** aggregate `sum()` / `average()`, vector values, bundles / named queries, `clearPersistence()`, multiple FirebaseApp instances, Auth providers other than email/password and anonymous (Google, Apple, phone…), profile updates and email verification flows.
- Listener snapshots arrive as events. A PHP request can't block waiting for the next snapshot; listen from a long-lived NativeComponent (or a global listener) instead.
- Very large query results travel through the bridge as JSON, so page them with `limit()` and cursors.

## License

MIT
