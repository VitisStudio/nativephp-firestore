<?php

namespace VitisStudio\NativeFirestore\Testing;

use PHPUnit\Framework\Assert as PHPUnit;
use VitisStudio\NativeFirestore\Contracts\MessagingDriver;
use VitisStudio\NativeFirestore\Events\MessagingTokenRefreshed;
use VitisStudio\NativeFirestore\Events\NotificationOpened;
use VitisStudio\NativeFirestore\Events\NotificationReceived;
use VitisStudio\NativeFirestore\Exceptions\FirestoreException;
use VitisStudio\NativeFirestore\FirebaseMessagingClient;

/**
 * In-memory stand-in for FCM on the phone. The permission prompt answers
 * with whatever answerPromptWith() set (granted by default), only the first
 * time, like iOS.
 */
class FakeFirebaseMessaging implements MessagingDriver
{
    private string $status = FirebaseMessagingClient::NOT_DETERMINED;

    private string $promptAnswer = FirebaseMessagingClient::GRANTED;

    private int $prompts = 0;

    private ?string $token = null;

    private int $issued = 0;

    private bool $tokenUnavailable = false;

    /** @var array<string, mixed>|null */
    private ?array $opened = null;

    private int $badge = 0;

    // -------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------

    public function answerPromptWith(string $status): static
    {
        $this->promptAnswer = $status;

        return $this;
    }

    /** The person already answered (or changed it in Settings). */
    public function setPermission(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** getToken() fails as it does on a simulator without push or offline. */
    public function withoutToken(bool $unavailable = true): static
    {
        $this->tokenUnavailable = $unavailable;

        return $this;
    }

    /** Firebase rotates the token and tells the app. */
    public function refreshToken(): string
    {
        $this->token = $this->newToken();
        event(new MessagingTokenRefreshed($this->token));

        return $this->token;
    }

    /**
     * A tap on a notification: fires NotificationOpened and is kept for
     * takeOpenedNotification(), as after a cold start.
     *
     * @param  array<string, mixed>  $data
     */
    public function tap(?string $title = null, ?string $body = null, array $data = []): static
    {
        $this->opened = compact('title', 'body', 'data');
        event(new NotificationOpened($title, $body, $data));

        return $this;
    }

    /** @param  array<string, mixed>  $data */
    public function receiveInForeground(?string $title = null, ?string $body = null, array $data = []): static
    {
        event(new NotificationReceived($title, $body, $data));

        return $this;
    }

    public function currentToken(): ?string
    {
        return $this->token;
    }

    public function prompts(): int
    {
        return $this->prompts;
    }

    public function badge(): int
    {
        return $this->badge;
    }

    public function assertPrompted(int $times = 1): static
    {
        PHPUnit::assertSame($times, $this->prompts, "Expected the notification prompt {$times} time(s).");

        return $this;
    }

    // -------------------------------------------------------------------
    // MessagingDriver
    // -------------------------------------------------------------------

    public function requestPermission(): string
    {
        if ($this->status === FirebaseMessagingClient::NOT_DETERMINED) {
            $this->prompts++;
            $this->status = $this->promptAnswer;
        }

        return $this->status;
    }

    public function permissionStatus(): string
    {
        return $this->status;
    }

    public function getToken(): string
    {
        if ($this->tokenUnavailable) {
            throw new FirestoreException('No APNS token specified before fetching FCM Token.', FirestoreException::UNAVAILABLE);
        }

        return $this->token ??= $this->newToken();
    }

    public function deleteToken(): void
    {
        $this->token = null;
    }

    public function takeOpenedNotification(): ?array
    {
        $opened = $this->opened;
        $this->opened = null;

        return $opened;
    }

    public function setBadgeCount(int $count): void
    {
        $this->badge = $count;
    }

    private function newToken(): string
    {
        return 'fake-fcm-token-'.(++$this->issued);
    }
}
