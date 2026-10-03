<?php

namespace VitisStudio\NativeFirestore\Testing;

use stdClass;

/**
 * Firestore's value ordering and equality, applied to wire-format values,
 * mirroring the SDK's model/value_util.cc. Used by the in-memory fake to
 * evaluate filters, orderBy, cursors and transforms the way the SDK does.
 *
 * Cross-type order: null < booleans < numbers < timestamps < strings < bytes
 * < references < geopoints < arrays < maps.
 *
 * Maps are stdClass objects (how the fake decodes JSON objects) so an empty
 * map never collapses into an empty array; a PHP array is a map only when it
 * has non-list keys.
 */
final class WireValues
{
    public static function isTagged(mixed $value, ?string $type = null): bool
    {
        return is_array($value)
            && isset($value['__type'])
            && is_string($value['__type'])
            && ($type === null || $value['__type'] === $type);
    }

    public static function isMap(mixed $value): bool
    {
        return $value instanceof stdClass
            || (is_array($value) && ! self::isTagged($value) && ! array_is_list($value));
    }

    public static function isList(mixed $value): bool
    {
        return is_array($value) && ! self::isTagged($value) && array_is_list($value);
    }

    public static function typeOrder(mixed $value): int
    {
        return match (true) {
            $value === null => 0,
            is_bool($value) => 1,
            is_int($value), is_float($value), self::isTagged($value, 'double') => 2,
            self::isTagged($value, 'timestamp') => 3,
            is_string($value) => 4,
            self::isTagged($value, 'bytes') => 5,
            self::isTagged($value, 'reference') => 6,
            self::isTagged($value, 'geopoint') => 7,
            self::isList($value) => 8,
            default => 9,
        };
    }

    public static function compare(mixed $a, mixed $b): int
    {
        $typeA = self::typeOrder($a);
        $typeB = self::typeOrder($b);

        if ($typeA !== $typeB) {
            return $typeA <=> $typeB;
        }

        return match ($typeA) {
            0 => 0,
            1 => $a <=> $b,
            2 => self::compareNumbers(self::number($a), self::number($b)),
            3 => [(int) $a['seconds'], (int) ($a['nanoseconds'] ?? 0)] <=> [(int) $b['seconds'], (int) ($b['nanoseconds'] ?? 0)],
            4 => strcmp($a, $b) <=> 0,
            5 => strcmp((string) base64_decode($a['base64']), (string) base64_decode($b['base64'])) <=> 0,
            6 => self::compareLists(explode('/', $a['path']), explode('/', $b['path'])),
            7 => [(float) $a['latitude'], (float) $a['longitude']] <=> [(float) $b['latitude'], (float) $b['longitude']],
            8 => self::compareLists($a, $b),
            default => self::compareMaps(self::entries($a), self::entries($b)),
        };
    }

    /**
     * The SDK's Equals(), used for membership (in, not-in, array-contains,
     * array-contains-any, arrayUnion/arrayRemove) and snapshot change checks.
     * Unlike compare(), numbers are equal only with the same type and bits:
     * 1 is not 1.0, NaN equals NaN, and -0.0 is not 0.0. Maps, though, are
     * equal when compare() finds them the same, so {a: 1} equals {a: 1.0}.
     */
    public static function equals(mixed $a, mixed $b): bool
    {
        $type = self::typeOrder($a);

        if ($type !== self::typeOrder($b)) {
            return false;
        }

        return match ($type) {
            0 => true,
            1, 4 => $a === $b,
            2 => self::numbersEqual(self::number($a), self::number($b)),
            3 => [(int) $a['seconds'], (int) ($a['nanoseconds'] ?? 0)] === [(int) $b['seconds'], (int) ($b['nanoseconds'] ?? 0)],
            5 => self::compare($a, $b) === 0,
            6 => $a['path'] === $b['path'],
            7 => (float) $a['latitude'] == (float) $b['latitude'] && (float) $a['longitude'] == (float) $b['longitude'],
            8 => count($a) === count($b) && array_filter(array_map(self::equals(...), array_values($a), array_values($b)), fn (bool $same) => ! $same) === [],
            default => count(self::entries($a)) === count(self::entries($b)) && self::compare($a, $b) === 0,
        };
    }

    public static function isNumber(mixed $value): bool
    {
        return self::typeOrder($value) === 2;
    }

    /**
     * A number's PHP value: int for integers, float for doubles (tagged
     * NaN and infinities included).
     */
    public static function number(mixed $value): float|int
    {
        if (self::isTagged($value, 'double')) {
            return match ($value['value']) {
                'NaN' => NAN,
                'Infinity' => INF,
                '-Infinity' => -INF,
                default => (float) $value['value'],
            };
        }

        return $value;
    }

    /**
     * A double in wire form: JSON cannot carry NaN or the infinities.
     *
     * @return float|array{__type: string, value: string}
     */
    public static function double(float $value): float|array
    {
        return match (true) {
            is_nan($value) => ['__type' => 'double', 'value' => 'NaN'],
            is_infinite($value) => ['__type' => 'double', 'value' => $value > 0 ? 'Infinity' : '-Infinity'],
            default => $value,
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function entries(mixed $map): array
    {
        return $map instanceof stdClass ? get_object_vars($map) : (array) $map;
    }

    /**
     * The SDK's CompareNumbers: NaN sorts before every other number, and an
     * integer and a double compare exactly (no rounding through float).
     */
    private static function compareNumbers(float|int $a, float|int $b): int
    {
        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }

        if (is_float($a) && is_float($b)) {
            return self::compareDoubles($a, $b);
        }

        return is_float($a) ? self::compareMixed($a, $b) : -self::compareMixed($b, $a);
    }

    private static function compareDoubles(float $a, float $b): int
    {
        if (is_nan($a) || is_nan($b)) {
            return is_nan($a) === is_nan($b) ? 0 : (is_nan($a) ? -1 : 1);
        }

        return $a <=> $b;
    }

    /**
     * util::CompareMixedNumber.
     */
    private static function compareMixed(float $double, int $integer): int
    {
        // -2^63 is exact as a double; 2^63 is the first double above PHP_INT_MAX.
        if ($double < (float) PHP_INT_MIN) {
            return -1;
        }

        if ($double >= -(float) PHP_INT_MIN) {
            return 1;
        }

        if (is_nan($double)) {
            return -1;
        }

        $truncated = (int) $double;

        if ($truncated !== $integer) {
            return $truncated <=> $integer;
        }

        return self::compareDoubles($double, (float) $integer);
    }

    /**
     * util::DoubleBitwiseEquals for doubles (NaN normalised); integers by value.
     */
    private static function numbersEqual(float|int $a, float|int $b): bool
    {
        if (is_int($a) || is_int($b)) {
            return $a === $b;
        }

        if (is_nan($a) || is_nan($b)) {
            return is_nan($a) && is_nan($b);
        }

        return pack('E', $a) === pack('E', $b);
    }

    /**
     * @param  array<int, mixed>  $a
     * @param  array<int, mixed>  $b
     */
    private static function compareLists(array $a, array $b): int
    {
        $a = array_values($a);
        $b = array_values($b);

        for ($i = 0, $n = min(count($a), count($b)); $i < $n; $i++) {
            $result = self::compare($a[$i], $b[$i]);

            if ($result !== 0) {
                return $result;
            }
        }

        return count($a) <=> count($b);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function compareMaps(array $a, array $b): int
    {
        ksort($a, SORT_STRING);
        ksort($b, SORT_STRING);

        $keysA = array_map('strval', array_keys($a));
        $keysB = array_map('strval', array_keys($b));

        for ($i = 0, $n = min(count($keysA), count($keysB)); $i < $n; $i++) {
            $keyResult = strcmp($keysA[$i], $keysB[$i]) <=> 0;

            if ($keyResult !== 0) {
                return $keyResult;
            }

            $valueResult = self::compare($a[$keysA[$i]], $b[$keysB[$i]]);

            if ($valueResult !== 0) {
                return $valueResult;
            }
        }

        return count($keysA) <=> count($keysB);
    }
}
