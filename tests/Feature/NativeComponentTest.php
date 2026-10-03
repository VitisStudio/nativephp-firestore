<?php

use App\NativeComponents\NotesScreen;
use Native\Mobile\Testing\Native;
use VitisStudio\NativeFirestore\Events\ListenerFailed;
use VitisStudio\NativeFirestore\Events\QuerySnapshotChanged;
use VitisStudio\NativeFirestore\Facades\Firestore;

require_once dirname(__DIR__, 2).'/examples/app/NativeComponents/NotesScreen.php';

/**
 * Drives the example screen as NativePHP would on device: #[On] handlers bind
 * the native event payload by parameter name.
 */
function replayInto($screen, $fake): void
{
    static $replayed = [];
    $key = spl_object_id($fake);

    foreach (array_slice($fake->emittedEvents(), $replayed[$key] ?? 0) as [$event, $payload]) {
        $screen->emitNative($event, $payload);
    }

    $replayed[$key] = count($fake->emittedEvents());
}

it('renders listener snapshots and writes through the API', function () {
    $fake = Firestore::fake();
    $fake->seed('notes/n1', ['text' => 'Buy milk', 'done' => false, 'createdAt' => now()->subMinute()]);

    $screen = Native::test(NotesScreen::class);
    replayInto($screen, $fake);

    $screen->assertSee('○ Buy milk')->assertSet('offline', false);

    $screen->set('draft', 'Walk the dog')->call('add');
    replayInto($screen, $fake);

    $fake->assertCount('notes', 2);
    $screen->assertSee('○ Walk the dog')->assertSet('draft', '');

    $screen->call('toggle', 'n1');
    replayInto($screen, $fake);
    $screen->assertSee('✓ Buy milk');

    $screen->call('remove', 'n1');
    replayInto($screen, $fake);
    $screen->assertDontSee('Buy milk');
});

it('ignores snapshots from other listeners and shows listener failures', function () {
    Firestore::fake();
    $screen = Native::test(NotesScreen::class);

    $screen->emitNative(QuerySnapshotChanged::class, [
        'listenerId' => 'someone-else',
        'path' => 'notes',
        'documents' => [['id' => 'x', 'path' => 'notes/x', 'exists' => true, 'data' => ['text' => 'Foreign', 'done' => false]]],
        'changes' => [],
        'fromCache' => true,
        'hasPendingWrites' => false,
    ])->assertDontSee('Foreign');

    $listenerId = $screen->instance()->listenerId;

    $screen->emitNative(ListenerFailed::class, ['listenerId' => $listenerId, 'code' => 'permission-denied', 'message' => 'Missing or insufficient permissions.'])
        ->assertSee('permission-denied: Missing or insufficient permissions.');
});

it('re-dispatches native snapshot events through Laravel for global listeners', function () {
    Firestore::fake();
    Illuminate\Support\Facades\Event::fake([QuerySnapshotChanged::class]);

    Native::test(NotesScreen::class)->emitNative(QuerySnapshotChanged::class, [
        'listenerId' => 'L9',
        'path' => 'notes',
        'documents' => [['id' => 'x', 'path' => 'notes/x', 'exists' => true, 'data' => ['text' => 'Hi']]],
        'changes' => [['type' => 'added', 'document' => ['id' => 'x', 'path' => 'notes/x', 'exists' => true, 'data' => ['text' => 'Hi']], 'oldIndex' => -1, 'newIndex' => 0]],
        'fromCache' => false,
        'hasPendingWrites' => true,
    ]);

    Illuminate\Support\Facades\Event::assertDispatched(QuerySnapshotChanged::class, function (QuerySnapshotChanged $event) {
        $snapshot = $event->snapshot();

        return $event->listenerId === 'L9'
            && $snapshot->hasPendingWrites()
            && $snapshot->docChanges()[0]->isAdded()
            && $snapshot->first()->get('text') === 'Hi';
    });
});
