<?php

namespace VitisStudio\NativeFirestore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use VitisStudio\NativeFirestore\DTO\DocumentSnapshot;

/**
 * A document listener produced a snapshot. The constructor parameters are
 * the native payload keys, so #[On] handlers can bind any of them by name.
 */
class DocumentChanged implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>|null  $data  wire-encoded; use snapshot() for decoded values
     */
    public function __construct(
        public string $listenerId,
        public string $path,
        public string $id = '',
        public bool $exists = false,
        public ?array $data = null,
        public bool $fromCache = false,
        public bool $hasPendingWrites = false,
    ) {}

    public function snapshot(): DocumentSnapshot
    {
        return DocumentSnapshot::fromArray([
            'id' => $this->id,
            'path' => $this->path,
            'exists' => $this->exists,
            'data' => $this->data,
            'metadata' => ['fromCache' => $this->fromCache, 'hasPendingWrites' => $this->hasPendingWrites],
        ]);
    }
}
