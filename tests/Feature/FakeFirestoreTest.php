<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use VitisStudio\NativeFirestore\DTO\DocumentChange;
use VitisStudio\NativeFirestore\DTO\GeoPoint;
use VitisStudio\NativeFirestore\DTO\Timestamp;
use VitisStudio\NativeFirestore\Events\DocumentChanged;
use VitisStudio\NativeFirestore\Events\QuerySnapshotChanged;
use VitisStudio\NativeFirestore\Exceptions\FirestoreException;
use VitisStudio\NativeFirestore\Facades\Firestore;
use VitisStudio\NativeFirestore\FieldValue;
use VitisStudio\NativeFirestore\Query\DocumentReference;
use VitisStudio\NativeFirestore\Query\FieldPath;
use VitisStudio\NativeFirestore\Query\Filter;
use VitisStudio\NativeFirestore\Query\Source;
use VitisStudio\NativeFirestore\Query\Transaction;
use VitisStudio\NativeFirestore\Query\WriteBatch;

beforeEach(function () {
    $this->fake = Firestore::fake();
});

describe('documents', function () {
    it('sets, gets, updates and deletes', function () {
        $ref = Firestore::doc('notes/n1');
        $ref->set(['title' => 'Hello', 'pinned' => false]);

        expect($ref->get()->data())->toBe(['title' => 'Hello', 'pinned' => false]);

        $ref->update(['pinned' => true]);
        expect($ref->get()->get('pinned'))->toBeTrue();

        $ref->delete();
        $snapshot = $ref->get();
        expect($snapshot->exists())->toBeFalse()
            ->and($snapshot->data())->toBeNull()
            ->and($snapshot->id())->toBe('n1');
    });

    it('adds documents with generated ids', function () {
        $ref = Firestore::collection('notes')->add(['title' => 'A']);

        expect($ref->id())->toMatch('/^[A-Za-z0-9]{20}$/');
        $this->fake->assertExists($ref->path(), ['title' => 'A'])->assertCount('notes', 1);
    });

    it('overwrites on set and deep-merges with merge', function () {
        $ref = Firestore::doc('users/u1');
        $ref->set(['name' => 'Ada', 'address' => ['city' => 'London', 'zip' => 'N1']]);

        $ref->set(['address' => ['city' => 'Paris']], merge: true);
        expect($ref->get()->data())->toBe(['name' => 'Ada', 'address' => ['city' => 'Paris', 'zip' => 'N1']]);

        $ref->set(['age' => 36]);
        expect($ref->get()->data())->toBe(['age' => 36]);
    });

    it('merges only the listed fields with mergeFields', function () {
        $ref = Firestore::doc('users/u1');
        $ref->set(['name' => 'Ada', 'age' => 36]);

        $ref->set(['name' => 'Grace', 'age' => 99, 'extra' => true], merge: ['name']);

        expect($ref->get()->data())->toBe(['name' => 'Grace', 'age' => 36]);
    });

    it('updates nested fields by dotted path without touching siblings', function () {
        $ref = Firestore::doc('users/u1');
        $ref->set(['address' => ['city' => 'London', 'zip' => 'N1']]);

        $ref->update(['address.city' => 'Leeds', 'stats.logins' => 1]);

        expect($ref->get()->data())->toBe([
            'address' => ['city' => 'Leeds', 'zip' => 'N1'],
            'stats' => ['logins' => 1],
        ]);
    });

    it('fails to update a missing document with not-found', function () {
        try {
            Firestore::doc('users/missing')->update(['a' => 1]);
            $this->fail('Expected a FirestoreException.');
        } catch (FirestoreException $exception) {
            expect($exception->isNotFound())->toBeTrue()
                ->and($exception->errorCode())->toBe('not-found');
        }
    });

    it('round-trips rich value types', function () {
        Firestore::doc('places/p1')->set([
            'at' => new Timestamp(1_700_000_000, 42),
            'where' => new GeoPoint(51.5, -0.1),
            'owner' => Firestore::doc('users/u1'),
            'price' => 10.0,
            'tags' => ['a', 'b'],
            'nested' => ['deep' => ['value' => null]],
        ]);

        $data = Firestore::doc('places/p1')->get()->data();

        expect($data['at'])->toEqual(new Timestamp(1_700_000_000, 42))
            ->and($data['where'])->toEqual(new GeoPoint(51.5, -0.1))
            ->and($data['owner'])->toBeInstanceOf(DocumentReference::class)
            ->and($data['owner']->path())->toBe('users/u1')
            ->and($data['price'])->toBe(10.0)
            ->and($data['tags'])->toBe(['a', 'b'])
            ->and($data['nested'])->toBe(['deep' => ['value' => null]]);

        // A decoded reference is live: it reads through the same (fake) client.
        Firestore::doc('users/u1')->set(['name' => 'Ada']);
        expect($data['owner']->get()->get('name'))->toBe('Ada');
    });
});

describe('field values', function () {
    it('resolves serverTimestamp, increment, arrayUnion, arrayRemove and delete', function () {
        CarbonImmutable::setTestNow('2026-05-01 12:00:00');

        $ref = Firestore::doc('counters/c1');
        $ref->set(['count' => 1, 'tags' => ['a'], 'temp' => true]);

        $ref->update([
            'count' => FieldValue::increment(2),
            'score' => FieldValue::increment(1.5),
            'tags' => FieldValue::arrayUnion('a', 'b', 'c'),
            'temp' => FieldValue::delete(),
            'updatedAt' => FieldValue::serverTimestamp(),
        ]);

        $data = $ref->get()->data();
        expect($data['count'])->toBe(3)
            ->and($data['score'])->toBe(1.5)
            ->and($data['tags'])->toBe(['a', 'b', 'c'])
            ->and($data)->not->toHaveKey('temp')
            ->and($data['updatedAt']->toDateTime()->toDateTimeString())->toBe('2026-05-01 12:00:00');

        $ref->update(['tags' => FieldValue::arrayRemove('a', 'c')]);
        expect($ref->get()->get('tags'))->toBe(['b']);

        CarbonImmutable::setTestNow();
    });

    it('applies transforms nested in maps on set', function () {
        Firestore::doc('stats/s1')->set(['totals' => ['views' => FieldValue::increment(5)]]);

        expect(Firestore::doc('stats/s1')->get()->get('totals.views'))->toBe(5);
    });

    it('rejects delete() in a plain set', function () {
        expect(fn () => Firestore::doc('a/b')->set(['x' => FieldValue::delete()]))
            ->toThrow(InvalidArgumentException::class, 'update() or set() with merge');
    });

    it('rejects sentinels anywhere beneath an array before they reach the driver', function (Closure $write) {
        expect($write)->toThrow(InvalidArgumentException::class, 'inside an array');

        expect($this->fake->commits())->toBe([]);
    })->with([
        'serverTimestamp in a map in an array' => [fn () => Firestore::doc('a/b')->set(['items' => [['at' => FieldValue::serverTimestamp()]]])],
        'increment deep under an array' => [fn () => Firestore::doc('a/b')->set(['items' => [['x' => ['y' => FieldValue::increment(1)]]]])],
        'arrayUnion in an array (update)' => [fn () => Firestore::doc('a/b')->update(['items' => [['tags' => FieldValue::arrayUnion('a')]]])],
        'delete in an array (merge)' => [fn () => Firestore::doc('a/b')->set(['items' => [['x' => FieldValue::delete()]]], merge: true)],
        'in a batch' => [fn () => Firestore::batch()->set(Firestore::doc('a/b'), ['items' => [['at' => FieldValue::serverTimestamp()]]])],
    ]);

    it('rejects delete() nested below the top level of update() data', function () {
        expect(fn () => Firestore::doc('a/b')->update(['meta' => ['x' => FieldValue::delete()]]))
            ->toThrow(InvalidArgumentException::class, 'top level of update()');
    });

    it('rejects reserved field names and invalid update paths in writes', function (Closure $write, string $message) {
        expect($write)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'reserved name in set' => [fn () => Firestore::doc('a/b')->set(['meta' => ['__secret__' => 1]]), 'reserved'],
        'reserved segment in update path' => [fn () => Firestore::doc('a/b')->update(['meta.__x__' => 1]), 'reserved'],
        'empty update path segment' => [fn () => Firestore::doc('a/b')->update(['meta..x' => 1]), 'Invalid field path'],
        'reserved character in update path' => [fn () => Firestore::doc('a/b')->update(['meta/x' => 1]), 'reserved character'],
        'invalid merge field' => [fn () => Firestore::doc('a/b')->set(['a' => 1], merge: ['a[0]']), 'reserved character'],
        'empty field name' => [fn () => Firestore::doc('a/b')->set(['' => 1]), 'cannot be empty'],
        'array directly in array' => [fn () => Firestore::doc('a/b')->set(['grid' => [[1, 2]]]), 'arrays directly inside arrays'],
    ]);

    it('still allows arrays of maps holding arrays and plain values', function () {
        Firestore::doc('a/b')->set(['items' => [['tags' => ['x', 'y'], 'at' => new Timestamp(1)]]]);

        expect(Firestore::doc('a/b')->get()->get('items.0.tags'))->toBe(['x', 'y']);
    });

    it('accepts delete() in a merge set', function () {
        Firestore::doc('a/b')->set(['x' => 1, 'y' => 2]);
        Firestore::doc('a/b')->set(['x' => FieldValue::delete()], merge: true);

        expect(Firestore::doc('a/b')->get()->data())->toBe(['y' => 2]);
    });
});

describe('queries', function () {
    beforeEach(function () {
        $this->fake->seedCollection('tasks', [
            't1' => ['title' => 'Write', 'priority' => 3, 'done' => false, 'tags' => ['work'], 'owner' => 'ann', 'due' => new Timestamp(300)],
            't2' => ['title' => 'Read', 'priority' => 1, 'done' => true, 'tags' => ['home', 'fun'], 'owner' => 'bob', 'due' => new Timestamp(100)],
            't3' => ['title' => 'Cook', 'priority' => 2, 'done' => false, 'tags' => ['home'], 'owner' => 'ann', 'due' => new Timestamp(200)],
            't4' => ['title' => 'Run', 'priority' => 2.5, 'done' => false, 'tags' => [], 'owner' => null],
            't5' => ['title' => 'Nap', 'done' => true],
        ]);
    });

    $ids = fn ($snapshot) => array_map(fn ($document) => $document->id(), $snapshot->documents());

    it('filters with every operator', function (string $field, string $operator, mixed $value, array $expected) use ($ids) {
        expect($ids(Firestore::collection('tasks')->where($field, $operator, $value)->get()))->toBe($expected);
    })->with([
        'equals' => ['done', '==', false, ['t1', 't3', 't4']],
        'not equals excludes missing and null' => ['owner', '!=', 'ann', ['t2']],
        'less than' => ['priority', '<', 2.5, ['t2', 't3']],
        'less or equal' => ['priority', '<=', 2.5, ['t2', 't3', 't4']],
        'greater than' => ['priority', '>', 2, ['t4', 't1']],
        'greater or equal' => ['priority', '>=', 2, ['t3', 't4', 't1']],
        'array contains' => ['tags', 'array-contains', 'home', ['t2', 't3']],
        'array contains any' => ['tags', 'array-contains-any', ['work', 'fun'], ['t1', 't2']],
        'in' => ['owner', 'in', ['bob', null], ['t2', 't4']],
        'not in' => ['owner', 'not-in', ['ann'], ['t2']],
        'not in with a null in the list matches nothing' => ['owner', 'not-in', ['bob', null], []],
        'equals null matches null but not missing' => ['owner', '==', null, ['t4']],
        'not equals null excludes missing' => ['owner', '!=', null, ['t1', 't3', 't2']],
        'in with only null matches null but not missing' => ['owner', 'in', [null], ['t4']],
        'array contains any skips missing arrays' => ['tags', 'array-contains-any', ['home', null], ['t2', 't3']],
        'inequality only matches the same type' => ['title', '>', 1, []],
        'timestamp range' => ['due', '>=', new Timestamp(200), ['t3', 't1']],
    ]);

    it('orders, limits and paginates with cursors', function () use ($ids) {
        $byPriority = Firestore::collection('tasks')->orderBy('priority');

        expect($ids($byPriority->get()))->toBe(['t2', 't3', 't4', 't1'])
            ->and($ids($byPriority->orderBy('title')->get()))->toBe(['t2', 't3', 't4', 't1'])
            ->and($ids(Firestore::collection('tasks')->orderByDesc('priority')->limit(2)->get()))->toBe(['t1', 't4'])
            ->and($ids($byPriority->limitToLast(2)->get()))->toBe(['t4', 't1'])
            ->and($ids($byPriority->startAt(2)->get()))->toBe(['t3', 't4', 't1'])
            ->and($ids($byPriority->startAfter(2)->get()))->toBe(['t4', 't1'])
            ->and($ids($byPriority->endAt(2.5)->get()))->toBe(['t2', 't3', 't4'])
            ->and($ids($byPriority->endBefore(2.5)->get()))->toBe(['t2', 't3']);

        $page1 = $byPriority->limit(2)->get();
        $page2 = $byPriority->startAfter($page1->documents()[1])->limit(2)->get();
        expect($ids($page2))->toBe(['t4', 't1']);
    });

    it('rejects a cursor document that lacks a field the query is ordered by', function () {
        $snapshot = Firestore::doc('tasks/t1')->get();
        Firestore::doc('tasks/t1')->update(['priority' => FieldValue::delete()]);

        expect(fn () => Firestore::collection('tasks')->orderBy('priority')->startAfter($snapshot)->get())
            ->toThrow(fn (FirestoreException $e) => expect($e->is(FirestoreException::INVALID_ARGUMENT))->toBeTrue());
    });

    it('orders implicitly by every inequality field and paginates over them with a document', function () use ($ids) {
        $query = Firestore::collection('tasks')->where('priority', '>=', 1)->where('owner', '!=', 'nobody');

        expect($ids($query->get()))->toBe(['t3', 't1', 't2'])
            ->and($ids($query->startAfter(Firestore::doc('tasks/t3')->get())->get()))->toBe(['t1', 't2']);
    });

    it('excludes documents missing the orderBy field', function () use ($ids) {
        expect($ids(Firestore::collection('tasks')->orderBy('owner')->get()))->toBe(['t4', 't1', 't3', 't2']);
    });

    it('supports OR filters and document-id filters', function () use ($ids) {
        $snapshot = Firestore::collection('tasks')
            ->where(Filter::or(Filter::where('owner', '==', 'bob'), Filter::where('priority', '>', 2.9)))
            ->get();

        expect($ids($snapshot))->toBe(['t2', 't1']) // implicitly ordered by the inequality field
            ->and($ids(Firestore::collection('tasks')->where(FieldPath::documentId(), 'in', ['t3', 't5'])->get()))->toBe(['t3', 't5'])
            ->and($ids(Firestore::collection('tasks')->orderBy(FieldPath::documentId(), 'desc')->limit(1)->get()))->toBe(['t5']);
    });

    it('queries nested fields', function () use ($ids) {
        Firestore::doc('tasks/t6')->set(['meta' => ['level' => 7]]);

        expect($ids(Firestore::collection('tasks')->where('meta.level', '==', 7)->get()))->toBe(['t6']);
    });

    it('runs collection group queries across parents', function () use ($ids) {
        Firestore::doc('users/a/notes/n1')->set(['public' => true]);
        Firestore::doc('users/b/notes/n2')->set(['public' => true]);
        Firestore::doc('users/b/notes/n3')->set(['public' => false]);
        Firestore::doc('notes/n4')->set(['public' => true]);

        expect($ids(Firestore::collectionGroup('notes')->where('public', true)->get()))->toBe(['n4', 'n1', 'n2']);
    });

    it('counts and returns first results', function () {
        expect(Firestore::collection('tasks')->count())->toBe(5)
            ->and(Firestore::collection('tasks')->where('done', true)->count())->toBe(2)
            ->and(Firestore::collection('tasks')->orderBy('priority')->first()->id())->toBe('t2')
            ->and(Firestore::collection('empty')->first())->toBeNull();
    });

    it('exposes snapshots as iterable, countable collections', function () {
        $snapshot = Firestore::collection('tasks')->where('owner', 'ann')->get();

        expect($snapshot)->toHaveCount(2)
            ->and($snapshot->isEmpty())->toBeFalse()
            ->and($snapshot->toArray()[0])->toMatchArray(['id' => 't1', 'title' => 'Write'])
            ->and(iterator_to_array($snapshot)[1]->id())->toBe('t3');
    });
});

describe('batches', function () {
    it('commits all writes atomically via a callback', function () {
        Firestore::doc('a/1')->set(['n' => 1]);

        $batch = Firestore::batch(function (WriteBatch $batch) {
            $batch->set('a/2', ['n' => 2])
                ->update(Firestore::doc('a/1'), ['n' => 10])
                ->delete('a/3');
        });

        expect($batch->count())->toBe(3);
        $this->fake->assertExists('a/1', ['n' => 10])->assertExists('a/2', ['n' => 2]);
    });

    it('applies nothing when one write fails', function () {
        Firestore::doc('a/1')->set(['n' => 1]);

        expect(fn () => Firestore::batch()
            ->set('a/2', ['n' => 2])
            ->update('a/missing', ['n' => 3])
            ->commit()
        )->toThrow(FirestoreException::class);

        $this->fake->assertMissing('a/2')->assertExists('a/1', ['n' => 1]);
    });

    it('cannot be committed twice or exceed 500 writes', function () {
        $batch = Firestore::batch();
        $batch->commit();

        expect(fn () => $batch->commit())->toThrow(LogicException::class);

        $big = Firestore::batch();
        foreach (range(1, 500) as $i) {
            $big->set("bulk/{$i}", ['i' => $i]);
        }

        expect(fn () => $big->set('bulk/501', []))->toThrow(LogicException::class);
    });
});

describe('transactions', function () {
    it('reads, writes and returns the callback result', function () {
        Firestore::doc('accounts/a')->set(['balance' => 100]);
        Firestore::doc('accounts/b')->set(['balance' => 0]);

        $moved = Firestore::transaction(function (Transaction $transaction) {
            $from = $transaction->get('accounts/a');
            $to = $transaction->get(Firestore::doc('accounts/b'));

            $transaction->update('accounts/a', ['balance' => $from->get('balance') - 30]);
            $transaction->update('accounts/b', ['balance' => $to->get('balance') + 30]);

            return 30;
        });

        expect($moved)->toBe(30);
        $this->fake->assertExists('accounts/a', ['balance' => 70])->assertExists('accounts/b', ['balance' => 30]);
    });

    it('retries the callback when a read document changes before commit', function () {
        Firestore::doc('counters/c')->set(['n' => 0]);
        $attempts = [];

        Firestore::transaction(function (Transaction $transaction) use (&$attempts) {
            $attempts[] = $transaction->attempt;
            $n = $transaction->get('counters/c')->get('n');

            if ($transaction->attempt === 1) {
                // Another writer gets in between our read and our commit.
                Firestore::doc('counters/c')->set(['n' => 100]);
            }

            $transaction->set('counters/c', ['n' => $n + 1]);
        });

        expect($attempts)->toBe([1, 2]);
        $this->fake->assertExists('counters/c', ['n' => 101]);
    });

    it('gives up with aborted after the maximum attempts', function () {
        Firestore::doc('counters/c')->set(['n' => 0]);

        try {
            Firestore::transaction(function (Transaction $transaction) {
                $transaction->get('counters/c');
                Firestore::doc('counters/c')->update(['n' => FieldValue::increment(1)]);
            }, maxAttempts: 3);
            $this->fail('Expected the transaction to abort.');
        } catch (FirestoreException $exception) {
            expect($exception->isAborted())->toBeTrue();
        }

        $this->fake->assertExists('counters/c', ['n' => 3]);
    });

    it('rolls back and rethrows when the callback throws', function () {
        Firestore::doc('a/1')->set(['n' => 1]);

        expect(fn () => Firestore::transaction(function (Transaction $transaction) {
            $transaction->set('a/1', ['n' => 2]);

            throw new RuntimeException('nope');
        }))->toThrow(RuntimeException::class, 'nope');

        $this->fake->assertExists('a/1', ['n' => 1]);
    });

    it('enforces reads before writes', function () {
        expect(fn () => Firestore::transaction(function (Transaction $transaction) {
            $transaction->set('a/1', ['n' => 1]);
            $transaction->get('a/2');
        }))->toThrow(LogicException::class);
    });

    it('needs a connection', function () {
        Firestore::disableNetwork();

        expect(fn () => Firestore::transaction(fn () => null))
            ->toThrow(fn (FirestoreException $e) => expect($e->isUnavailable())->toBeTrue());
    });
});

describe('listeners', function () {
    it('fires an initial query snapshot and then incremental changes', function () {
        Event::fake([QuerySnapshotChanged::class]);
        Firestore::doc('notes/a')->set(['n' => 1]);

        $listenerId = Firestore::collection('notes')->orderBy('n')->listen();

        Firestore::doc('notes/b')->set(['n' => 2]);
        Firestore::doc('notes/a')->update(['n' => 3]);
        Firestore::doc('notes/b')->delete();
        Firestore::doc('other/x')->set(['n' => 1]);

        $snapshots = [];
        Event::assertDispatched(QuerySnapshotChanged::class, function (QuerySnapshotChanged $event) use ($listenerId, &$snapshots) {
            $snapshots[] = $event->snapshot();

            return $event->listenerId === $listenerId && $event->path === 'notes';
        });

        expect($snapshots)->toHaveCount(4);

        $types = fn ($snapshot) => array_map(fn (DocumentChange $change) => $change->type.':'.$change->document->id, $snapshot->docChanges());

        expect($types($snapshots[0]))->toBe(['added:a'])
            ->and($types($snapshots[1]))->toBe(['added:b'])
            ->and($types($snapshots[2]))->toBe(['modified:a'])
            ->and($snapshots[2]->docChanges()[0]->oldIndex)->toBe(0)
            ->and($snapshots[2]->docChanges()[0]->newIndex)->toBe(1)
            ->and($types($snapshots[3]))->toBe(['removed:b'])
            ->and($snapshots[3]->size())->toBe(1)
            ->and($snapshots[3]->fromCache())->toBeFalse();
    });

    it('listens to a single document', function () {
        Event::fake([DocumentChanged::class]);

        $listenerId = Firestore::doc('profiles/me')->listen();
        Firestore::doc('profiles/me')->set(['name' => 'Ada']);
        Firestore::doc('profiles/me')->set(['name' => 'Ada']);

        $events = Event::dispatched(DocumentChanged::class)->map(fn (array $args) => $args[0]);

        expect($events)->toHaveCount(2)
            ->and($events[0]->exists)->toBeFalse()
            ->and($events[1]->snapshot()->get('name'))->toBe('Ada')
            ->and($events[1]->listenerId)->toBe($listenerId);
    });

    it('stops listening', function () {
        Event::fake([QuerySnapshotChanged::class]);

        $id = Firestore::collection('notes')->listen();
        $this->fake->assertListening($id);

        Firestore::stop($id);
        Firestore::doc('notes/a')->set(['n' => 1]);

        $this->fake->assertNotListening();
        Event::assertDispatchedTimes(QuerySnapshotChanged::class, 1);

        Firestore::collection('notes')->listen();
        Firestore::doc('notes/a')->listen();
        Firestore::stopAll();
        $this->fake->assertNotListening();
    });

    it('records emitted payloads for replay into native components', function () {
        Firestore::collection('notes')->listen();

        [$event, $payload] = $this->fake->emittedEvents()[0];

        expect($event)->toBe(QuerySnapshotChanged::class)
            ->and(array_keys($payload))->toBe(['listenerId', 'path', 'documents', 'changes', 'fromCache', 'hasPendingWrites']);
    });
});

describe('network and failures', function () {
    it('serves cache reads but fails server reads while offline', function () {
        Firestore::doc('a/1')->set(['n' => 1]);
        Firestore::disableNetwork();

        expect(Firestore::doc('a/1')->get()->fromCache())->toBeTrue()
            ->and(fn () => Firestore::doc('a/1')->get(Source::Server))->toThrow(FirestoreException::class)
            ->and(fn () => Firestore::collection('a')->count())->toThrow(FirestoreException::class);

        Firestore::enableNetwork();
        expect(Firestore::doc('a/1')->get(Source::Server)->fromCache())->toBeFalse();
    });

    it('simulates backend errors', function () {
        $this->fake->failNext(FirestoreException::PERMISSION_DENIED, 'Missing or insufficient permissions.');

        try {
            Firestore::doc('secret/x')->get();
            $this->fail('Expected permission-denied.');
        } catch (FirestoreException $exception) {
            expect($exception->isPermissionDenied())->toBeTrue()
                ->and($exception->getMessage())->toBe('Missing or insufficient permissions.');
        }

        expect(Firestore::doc('secret/x')->get()->exists())->toBeFalse();
    });

    it('reports fake status', function () {
        expect(Firestore::status())->toMatchArray(['configured' => true, 'fake' => true]);
    });
});
