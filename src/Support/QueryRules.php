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

    private const MAX_DISJUNCTIONS = 30;

    private const MAX_NOT_IN_VALUES = 10;

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
     * The SDKs themselves only refuse a second != or not-in, and not-in next
     * to !=, in or array-contains-any, anywhere in the query (iOS by raising
     * an uncatchable exception). The rest are the server's limits, which are
     * judged per disjunction of the query's disjunctive normal form, where
     * in and array-contains-any count one term per value: at most 30 terms,
     * at most one array-contains per term, no not-in beside an OR, and list
     * sizes of 30 (10 for not-in). Checking them up front keeps the fake, the
     * offline cache and the server in agreement.
     *
     * @param  list<array<string, mixed>>  $filters
     */
    private static function validateFilters(array $filters): void
    {
        $counts = ['disjunctive' => 0, 'notIn' => 0, 'notEqual' => 0, 'or' => 0];

        foreach (self::flatten($filters, $counts) as $filter) {
            $operator = $filter['op'] ?? '';
            $isArrayOperator = in_array($operator, ['array-contains', 'array-contains-any'], true);

            if (($filter['field'] ?? '') === self::DOCUMENT_ID && $isArrayOperator) {
                throw new InvalidArgumentException("The [{$operator}] operator cannot be used on the document id.");
            }

            if (in_array($operator, ['in', 'not-in', 'array-contains-any'], true)) {
                $limit = $operator === 'not-in' ? self::MAX_NOT_IN_VALUES : self::MAX_DISJUNCTIONS;

                if (count((array) $filter['value']) > $limit) {
                    throw new InvalidArgumentException("The [{$operator}] operator supports at most {$limit} values.");
                }
            }

            if ($operator === 'array-contains-any') {
                foreach ((array) $filter['value'] as $value) {
                    // Tagged values (timestamps, references...) are arrays on the wire too.
                    if (is_array($value) && array_is_list($value)) {
                        throw new InvalidArgumentException('array-contains-any values cannot be arrays (Firestore does not support nested arrays).');
                    }
                }
            }

            // Same rule as the native bridges: null and NaN only make sense for ==, !=
            // and array-contains (which matches arrays holding null/NaN).
            if (in_array($operator, ['<', '<=', '>', '>='], true) && self::isNullOrNaN($filter['value'] ?? null)) {
                throw new InvalidArgumentException('null and NaN only support ==, != and array-contains comparisons.');
            }

            match ($operator) {
                'array-contains-any', 'in' => $counts['disjunctive']++,
                'not-in' => $counts['notIn']++,
                '!=' => $counts['notEqual']++,
                default => null,
            };
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

        // The top-level filters are an implicit AND.
        [$terms, $arrayContains] = self::normalForm(['composite' => 'and', 'filters' => $filters]);

        if ($terms > self::MAX_DISJUNCTIONS) {
            throw new InvalidArgumentException('A query can have at most '.self::MAX_DISJUNCTIONS.' disjunctions once its OR, in and array-contains-any filters are expanded.');
        }

        if ($arrayContains > 1) {
            throw new InvalidArgumentException('Each disjunction of a query can use at most one array-contains or array-contains-any filter.');
        }
    }

    /**
     * The number of terms in a filter's disjunctive normal form (capped just
     * past the limit) and the most array-contains filters any one term holds.
     * in and array-contains-any expand into one == / array-contains per value.
     *
     * @param  array<string, mixed>  $filter
     * @return array{0: int, 1: int}
     */
    private static function normalForm(array $filter): array
    {
        if (! isset($filter['composite'])) {
            $operator = $filter['op'] ?? '';
            $terms = in_array($operator, ['in', 'array-contains-any'], true) ? max(1, count((array) $filter['value'])) : 1;

            return [min($terms, self::MAX_DISJUNCTIONS + 1), in_array($operator, ['array-contains', 'array-contains-any'], true) ? 1 : 0];
        }

        $isOr = $filter['composite'] === 'or';
        $terms = $isOr ? 0 : 1;
        $arrayContains = 0;

        foreach ($filter['filters'] ?? [] as $child) {
            [$childTerms, $childArrayContains] = self::normalForm($child);
            $terms = min($isOr ? $terms + $childTerms : $terms * $childTerms, self::MAX_DISJUNCTIONS + 1);
            // An AND term takes one term from every child; an OR term is one child's term.
            $arrayContains = $isOr ? max($arrayContains, $childArrayContains) : $arrayContains + $childArrayContains;
        }

        return [$terms, $arrayContains];
    }

    /** A wire operand that is null or the tagged NaN double. */
    private static function isNullOrNaN(mixed $value): bool
    {
        return $value === null
            || (is_float($value) && is_nan($value))
            || (is_array($value) && ($value['__type'] ?? null) === 'double' && ($value['value'] ?? null) === 'NaN');
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
