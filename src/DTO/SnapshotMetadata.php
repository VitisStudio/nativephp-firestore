<?php

namespace VitisStudio\NativeFirestore\DTO;

final class SnapshotMetadata
{
    /**
     * @param  list<string>  $pendingServerTimestamps  field paths holding a server timestamp not yet committed
     */
    public function __construct(
        public readonly bool $fromCache = false,
        public readonly bool $hasPendingWrites = false,
        public readonly array $pendingServerTimestamps = [],
    ) {}

    /**
     * @param  array{fromCache?: bool, hasPendingWrites?: bool, pendingServerTimestamps?: list<string>}  $metadata
     */
    public static function fromArray(array $metadata): self
    {
        return new self(
            (bool) ($metadata['fromCache'] ?? false),
            (bool) ($metadata['hasPendingWrites'] ?? false),
            array_values(array_map('strval', (array) ($metadata['pendingServerTimestamps'] ?? []))),
        );
    }

    /**
     * @return array{fromCache: bool, hasPendingWrites: bool, pendingServerTimestamps: list<string>}
     */
    public function toArray(): array
    {
        return ['fromCache' => $this->fromCache, 'hasPendingWrites' => $this->hasPendingWrites, 'pendingServerTimestamps' => $this->pendingServerTimestamps];
    }
}
