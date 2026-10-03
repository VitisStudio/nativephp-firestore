<?php

namespace VitisStudio\NativeFirestore\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use VitisStudio\NativeFirestore\DTO\QuerySnapshot;

/**
 * A query listener produced a snapshot. The constructor parameters are the
 * native payload keys, so #[On] handlers can bind any of them by name and
 * rebuild the DTO with QuerySnapshot::fromArray(compact('documents', 'changes')).
 */
class QuerySnapshotChanged implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * @param  list<array<string, mixed>>  $documents  wire-encoded document snapshots
     * @param  list<array<string, mixed>>  $changes  wire-encoded document changes
     */
    public function __construct(
        public string $listenerId,
        public string $path,
        public array $documents = [],
        public array $changes = [],
        public bool $fromCache = false,
        public bool $hasPendingWrites = false,
    ) {}

    public function snapshot(): QuerySnapshot
    {
        return QuerySnapshot::fromArray([
            'documents' => $this->documents,
            'changes' => $this->changes,
            'fromCache' => $this->fromCache,
            'hasPendingWrites' => $this->hasPendingWrites,
        ]);
    }
}
