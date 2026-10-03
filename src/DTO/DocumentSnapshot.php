<?php

namespace VitisStudio\NativeFirestore\DTO;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use VitisStudio\NativeFirestore\Query\DocumentReference;
use VitisStudio\NativeFirestore\Support\ValueCodec;

/**
 * Data comes back decoded: maps as associative arrays, except maps an array
 * would mistake for a list (empty, or keyed "0".."n-1"), which are MapValue
 * objects so they stay maps if written back.
 *
 * @implements Arrayable<string, mixed>
 */
final class DocumentSnapshot implements Arrayable
{
    /**
     * @param  array<string, mixed>|null  $data  decoded data
     * @param  array<string, mixed>|null  $wireData  the data as native code sent it, used for query cursors
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly bool $exists,
        private readonly ?array $data,
        public readonly SnapshotMetadata $metadata = new SnapshotMetadata,
        private readonly ?array $wireData = null,
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
        $data = $exists ? ValueCodec::unwrapMap((array) ($wire['data'] ?? [])) : null;

        return new self(
            id: (string) ($wire['id'] ?? basename($path)),
            path: $path,
            exists: $exists,
            data: $data === null ? null : ValueCodec::decodeMap($data),
            metadata: SnapshotMetadata::fromArray((array) ($wire['metadata'] ?? [])),
            wireData: $data,
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

    /**
     * A field's value exactly as Firestore sent it (typed values still
     * tagged), as [found, value]. Query cursors are built from this so a
     * value never round-trips through its decoded PHP form.
     *
     * @internal
     *
     * @return array{0: bool, 1: mixed}
     */
    public function wireField(string $field): array
    {
        if ($this->data === null) {
            return [false, null];
        }

        if ($this->wireData === null) {
            return Arr::has($this->data, $field)
                ? [true, ValueCodec::encodeArgument(Arr::get($this->data, $field))]
                : [false, null];
        }

        return ValueCodec::wireField($this->wireData, $field);
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
