<?php

namespace VitisStudio\NativeFirestore\DTO;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use VitisStudio\NativeFirestore\Query\DocumentReference;
use VitisStudio\NativeFirestore\Support\ValueCodec;

/**
 * @implements Arrayable<string, mixed>
 */
final class DocumentSnapshot implements Arrayable
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly bool $exists,
        private readonly ?array $data,
        public readonly SnapshotMetadata $metadata = new SnapshotMetadata,
    ) {}

    /**
     * Build a snapshot from its wire form — the shape native code returns and
     * the shape event payloads carry.
     *
     * @param  array{id?: string, path: string, exists?: bool, data?: array<string, mixed>|null, metadata?: array{fromCache?: bool, hasPendingWrites?: bool, pendingServerTimestamps?: list<string>}}  $wire
     */
    public static function fromArray(array $wire): self
    {
        $path = (string) $wire['path'];
        $exists = (bool) ($wire['exists'] ?? isset($wire['data']));

        return new self(
            id: (string) ($wire['id'] ?? basename($path)),
            path: $path,
            exists: $exists,
            data: $exists ? ValueCodec::decodeMap((array) ($wire['data'] ?? [])) : null,
            metadata: SnapshotMetadata::fromArray((array) ($wire['metadata'] ?? [])),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        return $this->data;
    }

    /**
     * Read a field by dotted path ("address.city").
     */
    public function get(string $field, mixed $default = null): mixed
    {
        if ($this->data === null) {
            return $default;
        }

        return Arr::get($this->data, $field, $default);
    }

    public function reference(): DocumentReference
    {
        return new DocumentReference($this->path);
    }

    public function fromCache(): bool
    {
        return $this->metadata->fromCache;
    }

    public function hasPendingWrites(): bool
    {
        return $this->metadata->hasPendingWrites;
    }

    /**
     * Whether the field (or something inside it) is a server timestamp that
     * has not been committed yet, so its real value is still unknown.
     */
    public function hasPendingServerTimestamp(string $field): bool
    {
        foreach ($this->metadata->pendingServerTimestamps as $pending) {
            if ($pending === $field || str_starts_with($pending, $field.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The document data plus its id, handy for views.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id] + ($this->data ?? []);
    }
}
