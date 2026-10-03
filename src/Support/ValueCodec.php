<?php

namespace VitisStudio\NativeFirestore\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;
use VitisStudio\NativeFirestore\DTO\Bytes;
use VitisStudio\NativeFirestore\DTO\GeoPoint;
use VitisStudio\NativeFirestore\DTO\Timestamp;
use VitisStudio\NativeFirestore\FieldValue;
use VitisStudio\NativeFirestore\Query\DocumentReference;

/**
 * Converts PHP values to the JSON wire format the native code understands and
 * back. Firestore types that JSON cannot express travel as tagged maps:
 * {"__type": "timestamp" | "geopoint" | "reference" | "bytes" | "fieldvalue" | "double", ...}.
 */
final class ValueCodec
{
    public const TYPE_KEY = '__type';

    /** Firestore allows 20 levels of nesting; the native decoders count the same way. */
    public const MAX_DEPTH = 20;

    /** A standalone value: sentinels allowed at the top, delete() included. */
    private const CONTEXT_VALUE = 'value';

    /** A query operand or arrayUnion/arrayRemove element: no sentinels at all. */
    private const CONTEXT_ARGUMENT = 'argument';

    private const CONTEXT_SET = 'set';

    private const CONTEXT_MERGE = 'merge';

    private const CONTEXT_UPDATE = 'update';

    public static function encode(mixed $value): mixed
    {
        return self::encodeValue($value, self::CONTEXT_VALUE, 0, false, false);
    }

    /**
     * Encode a query operand, cursor value or arrayUnion/arrayRemove element,
     * where Firestore rejects every FieldValue sentinel.
     */
    public static function encodeArgument(mixed $value): mixed
    {
        return self::encodeValue($value, self::CONTEXT_ARGUMENT, 0, false, false);
    }

    /**
     * Encode document data. Always produces a JSON object, even when empty.
     *
     * @param  array<array-key, mixed>|stdClass  $data
     */
    public static function encodeMap(array|stdClass $data): stdClass
    {
        return self::encodeObject((array) $data, self::CONTEXT_VALUE, 0, false);
    }

    /**
     * Encode the data of a set() write, enforcing the rules the Firebase SDKs
     * would otherwise enforce by raising (an uncatchable exception on iOS).
     *
     * @param  array<array-key, mixed>|stdClass  $data
     */
    public static function encodeSetData(array|stdClass $data, bool $merge): stdClass
    {
        return self::encodeObject((array) $data, $merge ? self::CONTEXT_MERGE : self::CONTEXT_SET, 0, false);
    }

    /**
     * Encode the data of an update() write: keys are dotted field paths and
     * delete() is allowed only as a top-level value.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function encodeUpdateData(array $data): stdClass
    {
        $encoded = new stdClass;

        foreach ($data as $field => $value) {
            foreach (Path::fieldSegments((string) $field) as $segment) {
                self::guardWritableName($segment);
            }

            $encoded->{(string) $field} = self::encodeValue($value, self::CONTEXT_UPDATE, 1, false, false);
        }

        return $encoded;
    }

    private static function encodeValue(mixed $value, string $context, int $depth, bool $underArray, bool $directlyInArray): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidArgumentException('Data is nested too deeply (Firestore allows '.self::MAX_DEPTH.' levels).');
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => self::encodeFloat($value),
            $value instanceof FieldValue => self::encodeSentinel($value, $context, $depth, $underArray),
            $value instanceof Timestamp,
            $value instanceof GeoPoint,
            $value instanceof Bytes,
            $value instanceof DocumentReference => $value->toWire(),
            $value instanceof DateTimeInterface => Timestamp::fromDateTime($value)->toWire(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof stdClass => self::encodeObject((array) $value, $context, $depth, $underArray),
            $value instanceof Arrayable => self::encodeValue($value->toArray(), $context, $depth, $underArray, $directlyInArray),
            $value instanceof JsonSerializable => self::encodeValue($value->jsonSerialize(), $context, $depth, $underArray, $directlyInArray),
            is_array($value) && array_is_list($value) => self::encodeList($value, $context, $depth, $directlyInArray),
            is_array($value) => self::encodeObject($value, $context, $depth, $underArray),
            default => throw new InvalidArgumentException('Unsupported Firestore value of type '.get_debug_type($value).'.'),
        };
    }

    /**
     * @param  list<mixed>  $list
     * @return list<mixed>
     */
    private static function encodeList(array $list, string $context, int $depth, bool $directlyInArray): array
    {
        if ($directlyInArray) {
            throw new InvalidArgumentException('Firestore does not support arrays directly inside arrays.');
        }

        return array_map(fn (mixed $element) => self::encodeValue($element, $context, $depth + 1, true, true), $list);
    }

    /**
     * @param  array<array-key, mixed>  $map
     */
    private static function encodeObject(array $map, string $context, int $depth, bool $underArray): stdClass
    {
        $encoded = new stdClass;

        foreach ($map as $key => $value) {
            $key = (string) $key;

            if ($key === self::TYPE_KEY) {
                throw new InvalidArgumentException('The field name "__type" is reserved by the Firestore plugin.');
            }

            if ($key === '') {
                throw new InvalidArgumentException('Document field names cannot be empty.');
            }

            if (in_array($context, [self::CONTEXT_SET, self::CONTEXT_MERGE, self::CONTEXT_UPDATE], true)) {
                self::guardWritableName($key);
            }

            $encoded->{$key} = self::encodeValue($value, $context, $depth + 1, $underArray, false);
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function encodeSentinel(FieldValue $value, string $context, int $depth, bool $underArray): array
    {
        $method = "FieldValue::{$value->operation}()";

        if ($context === self::CONTEXT_ARGUMENT) {
            throw new InvalidArgumentException("{$method} cannot be used in a query or inside arrayUnion/arrayRemove.");
        }

        if ($underArray) {
            throw new InvalidArgumentException("{$method} cannot be used inside an array.");
        }

        if ($value->operation === FieldValue::DELETE) {
            match (true) {
                $context === self::CONTEXT_SET => throw new InvalidArgumentException('FieldValue::delete() can only be used with update() or set() with merge.'),
                $context === self::CONTEXT_UPDATE && $depth > 1 => throw new InvalidArgumentException('FieldValue::delete() can only appear at the top level of update() data.'),
                default => null,
            };
        }

        $wire = [self::TYPE_KEY => 'fieldvalue', 'op' => $value->operation];

        if ($value->operation === FieldValue::INCREMENT) {
            $wire['value'] = $value->value;
        }

        if ($value->operation === FieldValue::ARRAY_UNION || $value->operation === FieldValue::ARRAY_REMOVE) {
            $wire['value'] = array_map(
                fn (mixed $element) => self::encodeValue($element, self::CONTEXT_ARGUMENT, $depth + 1, true, true),
                $value->value,
            );
        }

        return $wire;
    }

    /**
     * Firestore reserves field names that begin and end with "__".
     */
    private static function guardWritableName(string $name): void
    {
        if (strlen($name) >= 2 && str_starts_with($name, '__') && str_ends_with($name, '__')) {
            throw new InvalidArgumentException("Field name [{$name}] is reserved: Firestore field names cannot begin and end with \"__\".");
        }
    }

    public static function decode(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (isset($value[self::TYPE_KEY]) && is_string($value[self::TYPE_KEY])) {
            return self::decodeTagged($value);
        }

        return array_map(self::decode(...), $value);
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    public static function decodeMap(array $map): array
    {
        return array_map(self::decode(...), $map);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function decodeTagged(array $value): mixed
    {
        return match ($value[self::TYPE_KEY]) {
            'timestamp' => new Timestamp((int) $value['seconds'], (int) ($value['nanoseconds'] ?? 0)),
            'geopoint' => new GeoPoint((float) $value['latitude'], (float) $value['longitude']),
            'reference' => new DocumentReference((string) $value['path']),
            'bytes' => Bytes::fromBase64((string) $value['base64']),
            'double' => match ($value['value']) {
                'NaN' => NAN,
                'Infinity' => INF,
                '-Infinity' => -INF,
                default => (float) $value['value'],
            },
            default => array_map(self::decode(...), $value),
        };
    }

    private static function encodeFloat(float $value): float|array
    {
        if (is_nan($value)) {
            return [self::TYPE_KEY => 'double', 'value' => 'NaN'];
        }

        if (is_infinite($value)) {
            return [self::TYPE_KEY => 'double', 'value' => $value > 0 ? 'Infinity' : '-Infinity'];
        }

        return $value;
    }

    /**
     * JSON-encode a wire payload, keeping 1.0 a double so Firestore stores the
     * same numeric type PHP had.
     */
    public static function json(mixed $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
