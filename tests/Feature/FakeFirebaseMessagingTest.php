<?php

use Illuminate\Support\Facades\Event;
use VitisStudio\NativeFirestore\Events\MessagingTokenRefreshed;
use VitisStudio\NativeFirestore\Events\NotificationOpened;
use VitisStudio\NativeFirestore\Events\NotificationReceived;
use VitisStudio\NativeFirestore\Exceptions\FirestoreException;
use VitisStudio\NativeFirestore\Facades\FirebaseMessaging;
use VitisStudio\NativeFirestore\FirebaseMessagingClient;

beforeEach(function () {
    $this->fake = FirebaseMessaging::fake();
});

it('prompts once, like iOS, and remembers the answer', function () {
    $this->fake->answerPromptWith(FirebaseMessagingClient::DENIED);

    expect(FirebaseMessaging::permissionStatus())->toBe('not_determined')
        ->and(FirebaseMessaging::requestPermission())->toBe('denied')
        ->and(FirebaseMessaging::requestPermission())->toBe('denied')
        ->and(FirebaseMessaging::allowed())->toBeFalse();

    $this->fake->assertPrompted(1);
});

it('counts provisional permission as allowed', function () {
    $this->fake->setPermission(FirebaseMessagingClient::PROVISIONAL);

    expect(FirebaseMessaging::allowed())->toBeTrue()
        ->and($this->fake->prompts())->toBe(0);
});

it('issues a stable token until it is deleted or rotated', function () {
    Event::fake([MessagingTokenRefreshed::class]);

    $token = FirebaseMessaging::getToken();
    expect(FirebaseMessaging::getToken())->toBe($token);

    FirebaseMessaging::deleteToken();
    expect($this->fake->currentToken())->toBeNull()
        ->and(FirebaseMessaging::getToken())->not->toBe($token);

    $rotated = $this->fake->refreshToken();
    Event::assertDispatched(MessagingTokenRefreshed::class, fn ($event) => $event->token === $rotated);
});

it('fails to issue a token the way a phone without APNs does', function () {
    $this->fake->withoutToken();

    expect(fn () => FirebaseMessaging::getToken())->toThrow(FirestoreException::class);
});

it('fires the tap and keeps it once for a cold start', function () {
    Event::fake([NotificationOpened::class, NotificationReceived::class]);

    $this->fake->tap('Ava finished Make bed', 'Tap to approve', ['screen' => 'today']);
    $this->fake->receiveInForeground('Ben finished Trash');

    Event::assertDispatched(NotificationOpened::class, fn ($event) => $event->notification()->get('screen') === 'today');
    Event::assertDispatched(NotificationReceived::class, fn ($event) => $event->title === 'Ben finished Trash');

    expect(FirebaseMessaging::takeOpenedNotification()?->title)->toBe('Ava finished Make bed')
        ->and(FirebaseMessaging::takeOpenedNotification())->toBeNull();
});

it('clamps the badge count', function () {
    FirebaseMessaging::setBadgeCount(-1);
    expect($this->fake->badge())->toBe(0);

    FirebaseMessaging::setBadgeCount(4);
    expect($this->fake->badge())->toBe(4);
});
