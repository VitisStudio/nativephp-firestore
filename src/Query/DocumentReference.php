<?php

namespace VitisStudio\NativeFirestore\Query;

use InvalidArgumentException;
use JsonSerializable;
use VitisStudio\NativeFirestore\DTO\DocumentSnapshot;
use VitisStudio\NativeFirestore\FirestoreClient;
use VitisStudio\NativeFirestore\Support\Path;
use VitisStudio\NativeFirestore\Support\ValueCodec;

/**
 * A document location. Also usable as a value: storing a DocumentReference in
 * a field writes a Firestore reference.
 */
class DocumentReference implements JsonSerializable
{
    private readonly string $path;

    public function __construct(string $path, private ?FirestoreClient $client = null)
    {
        $this->path = Path::document($path);
    }

    public function id(): string
    {
        return Path::lastSegment($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function parent(): CollectionReference
    {
        return new CollectionReference((string) Path::parent($this->path), $this->client);
    }

    public function collection(string $path): CollectionReference
    {
        return new CollectionReference($this->path.'/'.trim($path, '/'), $this->client);
    }

    public function get(Source $source = Source::Default): DocumentSnapshot
    {
        return DocumentSnapshot::fromArray($this->client()->driver()->getDocument($this->path, $source->value));
    }

    /**
     * Overwrite the document, or merge into it. $merge may be true (merge
     * every given field) or a list of field paths to merge.
     *
     * @param  array<string, mixed>  $data
     * @param  bool|list<string>  $merge
     */
    public function set(array $data, bool|array $merge = false): void
    {
        $this->client()->driver()->commitWrites(
            [self::setWrite($this->path, $data, $merge)],
            $this->client()->waitsForServer(),
        );
    }

    /**
     * Update fields of an existing document; fails with not-found when it
     * does not exist. Keys may be dotted field paths ("address.city").
     *
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): void
    {
        $this->client()->driver()->commitWrites(
            [self::updateWrite($this->path, $data)],
            $this->client()->waitsForServer(),
        );
    }

    public function delete(): void
    {
        $this->client()->driver()->commitWrites(
            [self::deleteWrite($this->path)],
            $this->client()->waitsForServer(),
        );
    }

    /**
     * Start a realtime listener; DocumentChanged events carry the snapshots.
     * Returns the listener id for Firestore::stop().
     */
    public function listen(bool $includeMetadataChanges = false): string
    {
        return $this->client()->listen($this, $includeMetadataChanges);
    }

    public function equals(DocumentReference $other): bool
    {
        return $this->path === $other->path;
    }

    /**
     * @return array{__type: 'reference', path: string}
     */
    public function toWire(): array
    {
        return ['__type' => 'reference', 'path' => $this->path];
    }

    public function jsonSerialize(): string
    {
        return $this->path;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  bool|list<string>  $merge
     * @return array<string, mixed>
     */
    public static function setWrite(string $path, array $data, bool|array $merge = false): array
    {
        $write = ['type' => 'set', 'path' => $path, 'data' => ValueCodec::encodeSetData($data, $merge !== false), 'merge' => $merge === true];

        if (is_array($merge)) {
            if ($merge === [] || ! array_is_list($merge)) {
                throw new InvalidArgumentException('Merge fields must be a non-empty list of field paths.');
            }

            foreach ($merge as $field) {
                Path::fieldSegments((string) $field);
            }

            $write['mergeFields'] = array_values($merge);
        }

        return $write;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function updateWrite(string $path, array $data): array
    {
        if ($data === []) {
            throw new InvalidArgumentException('update() needs at least one field.');
        }

        return ['type' => 'update', 'path' => $path, 'data' => ValueCodec::encodeUpdateData($data)];
    }

    /**
     * @return array{type: 'delete', path: string}
     */
    public static function deleteWrite(string $path): array
    {
        return ['type' => 'delete', 'path' => $path];
    }

    private function client(): FirestoreClient
    {
        return $this->client ??= app(FirestoreClient::class);
    }
}
