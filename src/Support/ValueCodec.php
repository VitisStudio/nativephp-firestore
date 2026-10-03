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

    public static function encode(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => self::encodeFloat($value),
            $value instanceof FieldValue,
            $value instanceof Timestamp,
            $value instanceof GeoPoint,
            $value instanceof Bytes,
            $value instanceof DocumentReference => $value->toWire(),
            $value instanceof DateTimeInterface => Timestamp::fromDateTime($value)->toWire(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof stdClass => self::encodeMap((array) $value),
            $value instanceof Arrayable => self::encode($value->toArray()),
            $value instanceof JsonSerializable => self::encode($value->jsonSerialize()),
            is_array($value) => array_is_list($value)
                ? array_map(self::encode(...), $value)
                : self::encodeMap($value),
            default => throw new InvalidArgumentException('Unsupported Firestore value of type '.get_debug_type($value).'.'),
        };
    }

    /**
     * Encode document data. Always produces a JSON object, even when empty.
     *
     * @param  array<array-key, mixed>|stdClass  $data
     */
    public static function encodeMap(array|stdClass $data): stdClass
    {
        $encoded = new stdClass;

        foreach ((array) $data as $key => $value) {
            if ($key === self::TYPE_KEY) {
                throw new InvalidArgumentException('The field name "__type" is reserved by the Firestore plugin.');
            }

            $encoded->{(string) $key} = self::encode($value);
        }

        return $encoded;
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
