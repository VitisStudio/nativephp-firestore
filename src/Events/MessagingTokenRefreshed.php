<?php

namespace VitisStudio\NativeFirestore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * Firebase issued this install a new FCM token; replace the stored one.
 */
class MessagingTokenRefreshed implements BroadcastsGlobally
{
    use Dispatchable;

    public function __construct(public string $token = '') {}
}
