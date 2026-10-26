<?php

namespace VitisStudio\NativeFirestore\Contracts;

/**
 * The operations behind the FirebaseMessaging API. Notifications cross this
 * boundary as the arrays PushNotification::fromArray() accepts.
 */
interface MessagingDriver
{
    /** @return string granted | denied | not_determined | provisional | ephemeral */
    public function requestPermission(): string;

    /** @return string granted | denied | not_determined | provisional | ephemeral */
    public function permissionStatus(): string;

    public function getToken(): string;

    public function deleteToken(): void;

    /**
     * @return array<string, mixed>|null
     */
    public function takeOpenedNotification(): ?array;

    public function setBadgeCount(int $count): void;
}
