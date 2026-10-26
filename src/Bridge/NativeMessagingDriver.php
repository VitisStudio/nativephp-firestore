<?php

namespace VitisStudio\NativeFirestore\Bridge;

use VitisStudio\NativeFirestore\Contracts\MessagingDriver;

class NativeMessagingDriver implements MessagingDriver
{
    public function __construct(private readonly NativeBridge $bridge) {}

    public function requestPermission(): string
    {
        return (string) ($this->bridge->call('FirebaseMessaging.RequestPermission')['status'] ?? 'not_determined');
    }

    public function permissionStatus(): string
    {
        return (string) ($this->bridge->call('FirebaseMessaging.PermissionStatus')['status'] ?? 'not_determined');
    }

    public function getToken(): string
    {
        return (string) $this->bridge->call('FirebaseMessaging.GetToken')['token'];
    }

    public function deleteToken(): void
    {
        $this->bridge->call('FirebaseMessaging.DeleteToken');
    }

    public function takeOpenedNotification(): ?array
    {
        $notification = $this->bridge->call('FirebaseMessaging.TakeOpenedNotification')['notification'] ?? null;

        return is_array($notification) ? $notification : null;
    }

    public function setBadgeCount(int $count): void
    {
        $this->bridge->call('FirebaseMessaging.SetBadgeCount', ['count' => $count]);
    }
}
