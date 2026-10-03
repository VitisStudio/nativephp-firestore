<?php

namespace VitisStudio\NativeFirestore\DTO;

use ArrayAccess;
use ArrayIterator;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use stdClass;
use Traversable;

/**
 * A Firestore map that a PHP array cannot tell apart from a list: an empty
 * map, or one whose keys are exactly "0", "1", … "n-1".
 *
 * Snapshots return every other map as a plain associative array; these come
 * back as a MapValue so that writing the data back (or using it as a query
 * cursor) keeps them maps. Reads work like an array (`$map['0']`,
 * `count()`, `foreach`, dotted `get()` paths); toArray() gives the entries.
 * The object is immutable: build a new one to change it.
 *
 * Writes accept a MapValue, a stdClass or `(object) []` wherever a map is
 * expected; `new MapValue` is an explicit empty map.
 *
 * @implements ArrayAccess<array-key, mixed>
 * @implements IteratorAggregate<array-key, mixed>
 * @implements Arrayable<array-key, mixed>
 */
final class MapValue implements Arrayable, ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  array<array-key, mixed>  $entries
     */
    public function __construct(private readonly array $entries = []) {}

    /**
     * Whether Firestore map entries need this wrapper to survive as a map.
     *
     * @param  array<array-key, mixed>  $entries
     */
    public static function isAmbiguous(array $entries): bool
    {
        return array_is_list($entries);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->entries);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->entries[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new LogicException('A MapValue is immutable; build a new one from toArray().');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new LogicException('A MapValue is immutable; build a new one from toArray().');
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }

    /**
     * Always a JSON object, even when empty.
     */
    public function jsonSerialize(): stdClass
    {
        return (object) $this->entries;
    }
}
