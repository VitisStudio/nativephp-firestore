<?php

namespace VitisStudio\NativeFirestore\DTO;

final class SnapshotMetadata
{
    public function __construct(
        public readonly bool $fromCache = false,
        public readonly bool $hasPendingWrites = false,
    ) {}

    /**
     * @param  array{fromCache?: bool, hasPendingWrites?: bool}  $metadata
     */
    public static function fromArray(array $metadata): self
    {
        return new self((bool) ($metadata['fromCache'] ?? false), (bool) ($metadata['hasPendingWrites'] ?? false));
    }

    /**
     * @return array{fromCache: bool, hasPendingWrites: bool}
     */
    public function toArray(): array
    {
        return ['fromCache' => $this->fromCache, 'hasPendingWrites' => $this->hasPendingWrites];
    }
}
