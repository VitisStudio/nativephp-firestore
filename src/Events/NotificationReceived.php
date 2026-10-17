<?php

namespace VitisStudio\NativeFirestore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use VitisStudio\NativeFirestore\DTO\PushNotification;

/**
 * A push arrived while the app was in the foreground (the banner still shows).
 */
class NotificationReceived implements BroadcastsGlobally
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
