<?php

namespace VitisStudio\NativeFirestore;

use VitisStudio\NativeFirestore\Contracts\MessagingDriver;
use VitisStudio\NativeFirestore\DTO\PushNotification;

/**
 * The object behind the FirebaseMessaging facade: permission, the FCM token
 * your backend sends to, and the notification that opened the app.
 */
class FirebaseMessagingClient
{
    public const GRANTED = 'granted';

    public const DENIED = 'denied';

    public const NOT_DETERMINED = 'not_determined';

    public const PROVISIONAL = 'provisional';

    public const EPHEMERAL = 'ephemeral';

    public function __construct(private readonly MessagingDriver $driver) {}

    /**
     * Show the system prompt (iOS asks only once; later calls return the
     * stored answer) and register for remote notifications when allowed.
     */
    public function requestPermission(): string
    {
        return $this->driver->requestPermission();
    }

    public function permissionStatus(): string
    {
        return $this->driver->permissionStatus();
    }

    /** Whether notifications will be shown (provisional and ephemeral count). */
    public function allowed(): bool
    {
        return in_array($this->driver->permissionStatus(), [self::GRANTED, self::PROVISIONAL, self::EPHEMERAL], true);
    }

    /**
     * This install's FCM registration token. Store it server-side to send to
     * the device; it can change (MessagingTokenRefreshed).
     *
     * @throws Exceptions\FirebaseException no APNs token (simulator without push, offline, …)
     */
    public function getToken(): string
    {
        return $this->driver->getToken();
    }

    /** Stop this install receiving pushes (sign-out). */
    public function deleteToken(): void
    {
        $this->driver->deleteToken();
    }

    /**
     * The notification whose tap opened the app, once. Call it on launch:
     * a tap that cold-starts the app fires NotificationOpened before PHP is
     * listening.
     */
    public function takeOpenedNotification(): ?PushNotification
    {
        $notification = $this->driver->takeOpenedNotification();

        return $notification === null ? null : PushNotification::fromArray($notification);
    }

    public function setBadgeCount(int $count): void
    {
        $this->driver->setBadgeCount(max(0, $count));
    }

    public function driver(): MessagingDriver
    {
        return $this->driver;
    }
}
