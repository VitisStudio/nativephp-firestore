<?php

namespace VitisStudio\NativeFirestore\Testing;

use stdClass;

/**
 * Firestore's value ordering and equality, applied to wire-format values.
 * Used by the in-memory fake to evaluate filters, orderBy and cursors the way
 * the real backend does.
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

    public static function equals(mixed $a, mixed $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function entries(mixed $map): array
    {
        return $map instanceof stdClass ? get_object_vars($map) : (array) $map;
    }

    private static function number(mixed $value): float|int
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

    private static function compareNumbers(float|int $a, float|int $b): int
    {
        // Firestore sorts NaN before every other number.
        $aNan = is_float($a) && is_nan($a);
        $bNan = is_float($b) && is_nan($b);

        if ($aNan || $bNan) {
            return $aNan === $bNan ? 0 : ($aNan ? -1 : 1);
        }

        return $a <=> $b;
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
