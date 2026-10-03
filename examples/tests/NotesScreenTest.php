<?php

// Copy into your app's tests/Feature to test the example screen without Firebase.

use App\NativeComponents\NotesScreen;
use Native\Mobile\Testing\Native;
use VitisStudio\NativeFirestore\Facades\Firestore;

it('shows notes from Firestore and adds new ones', function () {
    $firestore = Firestore::fake();
    $firestore->seed('notes/n1', ['text' => 'Buy milk', 'done' => false, 'createdAt' => now()]);

    $screen = Native::test(NotesScreen::class);

    // The fake fires listener events synchronously; replay them into the screen.
    foreach ($firestore->emittedEvents() as [$event, $payload]) {
        $screen->emitNative($event, $payload);
    }

    $screen->assertSee('○ Buy milk');

    $screen->set('draft', 'Walk the dog')->call('add');
    $firestore->assertCount('notes', 2);
});
