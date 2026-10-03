## vitisstudio/nativephp-firestore

Cloud Firestore + Firebase Auth for NativePHP Mobile through the native Firebase SDKs.

### Usage
- Facades: `VitisStudio\NativeFirestore\Facades\Firestore` and `VitisStudio\NativeFirestore\Facades\FirebaseAuth`.
- References: `Firestore::collection('notes')`, `Firestore::doc('notes/n1')`, `Firestore::collectionGroup('notes')`.
- Queries are immutable: `->where($field, $op, $value)` (all Firestore operators; `Filter::or()` / `Filter::and()`), `->orderBy()`, `->limit()`, `->limitToLast()`, `->startAt()/startAfter()/endAt()/endBefore()`, then `->get()` (QuerySnapshot), `->first()`, `->count()`.
- Writes: `->set($data, merge: true|['field'])`, `->update(['a.b' => 1])`, `->delete()`, `CollectionReference::add()`; sentinels via `VitisStudio\NativeFirestore\FieldValue`.
- Writes return once applied locally (offline-first); server rejections arrive as `Events\WriteFailed`. `Firestore::waitForServer()` makes writes block.
- `Firestore::batch(fn (WriteBatch $b) => ...)`; `Firestore::transaction(fn (Transaction $tx) => ...)` — reads before writes, callback may re-run on contention, needs a connection.
- Realtime: `->listen()` returns a listener id; handle `Events\QuerySnapshotChanged` / `Events\DocumentChanged` in NativeComponents with `#[On(...)]`, filter by `$listenerId`, rebuild DTOs with `QuerySnapshot::fromArray(compact('documents', 'changes'))`; stop with `Firestore::stop($id)` (e.g. in `registerCleanup`).
- Errors: `Exceptions\FirestoreException` / `FirebaseAuthException` with `errorCode()` (`permission-denied`, `not-found`, `unavailable`, `not-configured`, `bridge-unavailable`, ...).

### Testing
- Always use `Firestore::fake()` / `FirebaseAuth::fake()` in tests; replay `$fake->emittedEvents()` into `Native::test()` components with `emitNative()`.
- In the fake, an awaited commit (wait-for-server on) fails with `unavailable` while offline; simulate server rejections with `$fake->rejectNextCommit()` and races with `$fake->beforeServerCommit(fn ($fake, $paths) => ...)`.

### Build
- Requires `php artisan native:run` builds; Jump cannot load the plugin's native code.
- Firebase config: project-root `GoogleService-Info.plist` / `google-services.json`, or `FIREBASE_PROJECT_ID` (+ app ids / api key); a `demo-*` project with `FIREBASE_EMULATOR=true` targets the Emulator Suite.
