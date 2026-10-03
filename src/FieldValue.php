<?php

namespace VitisStudio\NativeFirestore;

use InvalidArgumentException;
use VitisStudio\NativeFirestore\Support\ValueCodec;

/**
 * Sentinel values resolved by Firestore when a write is applied.
 */
final class FieldValue
{
    public const SERVER_TIMESTAMP = 'serverTimestamp';

    public const INCREMENT = 'increment';

    public const ARRAY_UNION = 'arrayUnion';

    public const ARRAY_REMOVE = 'arrayRemove';

    public const DELETE = 'delete';

    private function __construct(
        public readonly string $operation,
        public readonly mixed $value = null,
    ) {}

    public static function serverTimestamp(): self
    {
        return new self(self::SERVER_TIMESTAMP);
    }

    public static function increment(int|float $by = 1): self
    {
        return new self(self::INCREMENT, $by);
    }

    public static function arrayUnion(mixed ...$elements): self
    {
        return new self(self::ARRAY_UNION, array_values($elements));
    }

    public static function arrayRemove(mixed ...$elements): self
    {
        return new self(self::ARRAY_REMOVE, array_values($elements));
    }

    public static function delete(): self
    {
        return new self(self::DELETE);
    }

    /**
     * @return array{__type: 'fieldvalue', op: string, value?: mixed}
     */
    public function toWire(): array
    {
        $wire = ['__type' => 'fieldvalue', 'op' => $this->operation];

        if ($this->operation === self::INCREMENT) {
            $wire['value'] = $this->value;
        }

        if ($this->operation === self::ARRAY_UNION || $this->operation === self::ARRAY_REMOVE) {
            $wire['value'] = array_map(function (mixed $element) {
                if ($element instanceof self) {
                    throw new InvalidArgumentException('FieldValue sentinels cannot be nested inside arrayUnion/arrayRemove.');
                }

                return ValueCodec::encode($element);
            }, $this->value);
        }

        return $wire;
    }
}
