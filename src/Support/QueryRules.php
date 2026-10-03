<?php

namespace VitisStudio\NativeFirestore\Support;

use InvalidArgumentException;

/**
 * The query rules the native SDKs enforce before a query ever reaches the
 * server. They are checked in PHP as well because the iOS SDK reports these
 * mistakes by raising Objective-C exceptions, which Swift cannot catch: a
 * query the native layer lets through by mistake takes the whole app down.
 * Checking here gives the fake and the device the same failure.
 */
final class QueryRules
{
    public const INEQUALITY_OPERATORS = ['!=', '<', '<=', '>', '>=', 'not-in'];

    private const DOCUMENT_ID = '__name__';

    /**
     * @param  array<string, mixed>  $wire
     *
     * @throws InvalidArgumentException
     */
    public static function validate(array $wire): void
    {
        self::validateFilters($wire['filters'] ?? []);

        $orderBy = $wire['orderBy'] ?? [];

        if (($wire['limitType'] ?? 'first') === 'last' && ($wire['limit'] ?? null) !== null && $orderBy === []) {
            throw new InvalidArgumentException('limitToLast() requires at least one orderBy() clause.');
        }

        foreach (['startAt', 'endAt'] as $edge) {
            $values = $wire[$edge]['values'] ?? null;

            if (is_array($values) && count($values) > count($orderBy)) {
                throw new InvalidArgumentException('A query cursor cannot have more values than the query has orderBy() clauses.');
            }
        }
    }

    /**
     * The fields a query is really sorted by: explicit orderBy() clauses, then
     * every inequality field not already ordered (in field-path order), then
     * the document id, the implicit fields following the last explicit
     * direction. A document cursor must hold a value for each of these.
     *
     * @param  array<string, mixed>  $wire
     * @return list<array{field: string, direction: string}>
     */
    public static function normalizedOrderBy(array $wire): array
    {
        $orderBy = array_values($wire['orderBy'] ?? []);
        $ordered = array_column($orderBy, 'field');
        $direction = $orderBy === [] ? 'asc' : end($orderBy)['direction'];

        $implicit = array_diff(
            array_unique(self::inequalityFields($wire['filters'] ?? [])),
            [...$ordered, self::DOCUMENT_ID],
        );
        usort($implicit, self::compareFieldPaths(...));

        foreach ($implicit as $field) {
            $orderBy[] = ['field' => $field, 'direction' => $direction];
        }

        if (! in_array(self::DOCUMENT_ID, $ordered, true)) {
            $orderBy[] = ['field' => self::DOCUMENT_ID, 'direction' => $direction];
        }

        return $orderBy;
    }

    /**
     * The normalized order fields a document cursor is missing.
     *
     * @param  array<string, mixed>  $wire
     * @param  callable(string): bool  $has
     * @return list<string>
     */
    public static function missingCursorFields(array $wire, callable $has): array
    {
        $missing = [];

        foreach (self::normalizedOrderBy($wire) as $order) {
            if ($order['field'] !== self::DOCUMENT_ID && ! $has($order['field'])) {
                $missing[] = $order['field'];
            }
        }

        return $missing;
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     */
    private static function validateFilters(array $filters): void
    {
        $counts = ['arrayContains' => 0, 'disjunctive' => 0, 'notIn' => 0, 'notEqual' => 0, 'or' => 0];

        foreach (self::flatten($filters, $counts) as $filter) {
            $operator = $filter['op'] ?? '';
            $isArrayOperator = in_array($operator, ['array-contains', 'array-contains-any'], true);

            if (($filter['field'] ?? '') === self::DOCUMENT_ID && $isArrayOperator) {
                throw new InvalidArgumentException("The [{$operator}] operator cannot be used on the document id.");
            }

            if ($operator === 'array-contains-any') {
                foreach ((array) $filter['value'] as $value) {
                    // Tagged values (timestamps, references...) are arrays on the wire too.
                    if (is_array($value) && array_is_list($value)) {
                        throw new InvalidArgumentException('array-contains-any values cannot be arrays (Firestore does not support nested arrays).');
                    }
                }
            }

            match ($operator) {
                'array-contains' => $counts['arrayContains']++,
                'array-contains-any' => [$counts['arrayContains']++, $counts['disjunctive']++],
                'in' => $counts['disjunctive']++,
                'not-in' => $counts['notIn']++,
                '!=' => $counts['notEqual']++,
                default => null,
            };
        }

        if ($counts['arrayContains'] > 1) {
            throw new InvalidArgumentException('A query can use at most one array-contains or array-contains-any filter.');
        }

        if ($counts['notIn'] > 1) {
            throw new InvalidArgumentException('A query can use at most one not-in filter.');
        }

        if ($counts['notEqual'] > 1) {
            throw new InvalidArgumentException('A query can use at most one != filter.');
        }

        if ($counts['notIn'] > 0 && ($counts['notEqual'] > 0 || $counts['disjunctive'] > 0 || $counts['or'] > 0)) {
            throw new InvalidArgumentException('not-in cannot be combined with !=, in, array-contains-any or OR filters.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     * @param  array<string, int>  $counts
     * @return list<array<string, mixed>>
     */
    private static function flatten(array $filters, array &$counts): array
    {
        $flat = [];

        foreach ($filters as $filter) {
            if (isset($filter['composite'])) {
                if ($filter['composite'] === 'or') {
                    $counts['or'] = ($counts['or'] ?? 0) + 1;
                }

                array_push($flat, ...self::flatten($filter['filters'] ?? [], $counts));
            } else {
                $flat[] = $filter;
            }
        }

        return $flat;
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     * @return list<string>
     */
    private static function inequalityFields(array $filters): array
    {
        $counts = ['or' => 0];

        return array_values(array_map(
            fn (array $filter) => (string) $filter['field'],
            array_filter(
                self::flatten($filters, $counts),
                fn (array $filter) => in_array($filter['op'] ?? '', self::INEQUALITY_OPERATORS, true),
            ),
        ));
    }

    private static function compareFieldPaths(string $a, string $b): int
    {
        $left = explode('.', $a);
        $right = explode('.', $b);

        foreach ($left as $index => $segment) {
            if (! isset($right[$index])) {
                return 1;
            }

            if (($result = strcmp($segment, $right[$index])) !== 0) {
                return $result;
            }
        }

        return count($left) <=> count($right);
    }
}
