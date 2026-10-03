<?php

namespace VitisStudio\NativeFirestore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * A snapshot listener was cancelled by Firestore (e.g. permission-denied).
 * The listener is already detached when this arrives.
 */
class ListenerFailed implements BroadcastsGlobally
{
    use Dispatchable;

    public function __construct(
        public string $listenerId,
        public string $code,
        public string $message,
    ) {}
}
