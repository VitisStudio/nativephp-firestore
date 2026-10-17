<?php

namespace VitisStudio\NativeFirestore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use VitisStudio\NativeFirestore\DTO\PushNotification;

/**
 * The person tapped a notification. A tap that cold-starts the app fires before PHP
 * listens; use FirebaseMessaging::takeOpenedNotification() on launch for that one.
 */
class NotificationOpened implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public ?string $title = null, public ?string $body = null, public array $data = []) {}

    public function notification(): PushNotification
    {
        return PushNotification::fromArray(['title' => $this->title, 'body' => $this->body, 'data' => $this->data]);
    }
}
