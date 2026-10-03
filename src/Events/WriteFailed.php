<?php

namespace VitisStudio\NativeFirestore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The backend rejected a write that was not awaited (wait_for_server off).
 * Firestore has already rolled the local cache back.
 */
class WriteFailed implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        public array $paths,
        public string $code,
        public string $message,
    ) {}
}
