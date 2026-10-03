<?php

namespace VitisStudio\NativeFirestore\DTO;

final class DocumentChange
{
    public const ADDED = 'added';

    public const MODIFIED = 'modified';

    public const REMOVED = 'removed';

    public function __construct(
        public readonly string $type,
        public readonly DocumentSnapshot $document,
        public readonly int $oldIndex,
        public readonly int $newIndex,
    ) {}

    /**
     * @param  array{type: string, document: array<string, mixed>, oldIndex?: int, newIndex?: int}  $wire
     */
    public static function fromArray(array $wire): self
    {
        return new self(
            type: (string) $wire['type'],
            document: DocumentSnapshot::fromArray($wire['document']),
            oldIndex: (int) ($wire['oldIndex'] ?? -1),
            newIndex: (int) ($wire['newIndex'] ?? -1),
        );
    }

    public function isAdded(): bool
    {
        return $this->type === self::ADDED;
    }

    public function isModified(): bool
    {
        return $this->type === self::MODIFIED;
    }

    public function isRemoved(): bool
    {
        return $this->type === self::REMOVED;
    }
}
