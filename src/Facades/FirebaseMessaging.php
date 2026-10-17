<?php

namespace VitisStudio\NativeFirestore\Facades;

use Illuminate\Support\Facades\Facade;
use VitisStudio\NativeFirestore\FirebaseMessagingClient;
use VitisStudio\NativeFirestore\Testing\FakeFirebaseMessaging;

/**
 * @method static string requestPermission()
 * @method static string permissionStatus()
 * @method static bool allowed()
 * @method static string getToken()
 * @method static void deleteToken()
 * @method static \VitisStudio\NativeFirestore\DTO\PushNotification|null takeOpenedNotification()
 * @method static void setBadgeCount(int $count)
 *
 * @see FirebaseMessagingClient
 */
class FirebaseMessaging extends Facade
{
    public static function fake(): FakeFirebaseMessaging
    {
        $fake = new FakeFirebaseMessaging;

        static::swap(new FirebaseMessagingClient($fake));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'firebase.messaging';
    }
}
