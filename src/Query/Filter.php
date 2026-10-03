<?php

namespace VitisStudio\NativeFirestore\Query;

use InvalidArgumentException;
use VitisStudio\NativeFirestore\Support\ValueCodec;

/**
 * A single field filter or an OR/AND composite of filters.
 */
final class Filter
{
    public const OPERATORS = [
        '==', '!=', '<', '<=', '>', '>=',
        'array-contains', 'array-contains-any', 'in', 'not-in',
    ];

    private const ALIASES = [
        '=' => '==',
        '<>' => '!=',
        'array_contains' => 'array-contains',
        'array_contains_any' => 'array-contains-any',
        'not_in' => 'not-in',
    ];

    /**
     * @param  list<Filter>  $filters
     */
    private function __construct(
        public readonly ?string $field = null,
        public readonly ?string $operator = null,
        public readonly mixed $value = null,
        public readonly ?string $composite = null,
        public readonly array $filters = [],
    ) {}

    public static function where(string $field, string $operator, mixed $value): self
    {
        $operator = self::ALIASES[$operator] ?? $operator;

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported Firestore operator [{$operator}].");
        }

        if (in_array($operator, ['in', 'not-in', 'array-contains-any'], true)) {
            if (! is_array($value) || ! array_is_list($value) || $value === []) {
                throw new InvalidArgumentException("The [{$operator}] operator needs a non-empty list of values.");
            }
        }

        return new self(field: $field, operator: $operator, value: $value);
    }

    public static function or(Filter ...$filters): self
    {
        return self::composite('or', $filters);
    }

    public static function and(Filter ...$filters): self
    {
        return self::composite('and', $filters);
    }

    public function isComposite(): bool
    {
        return $this->composite !== null;
    }

    public function isInequality(): bool
    {
        return in_array($this->operator, ['!=', '<', '<=', '>', '>=', 'not-in'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        if ($this->composite !== null) {
            return [
                'composite' => $this->composite,
                'filters' => array_map(fn (Filter $filter) => $filter->toWire(), $this->filters),
            ];
        }

        return ['field' => $this->field, 'op' => $this->operator, 'value' => $this->encodedValue()];
    }

    /**
     * List operators carry one operand per element, so each element is
     * encoded on its own (an in() element may itself be an array).
     */
    private function encodedValue(): mixed
    {
        if (in_array($this->operator, ['in', 'not-in', 'array-contains-any'], true) && is_array($this->value) && array_is_list($this->value)) {
            return array_map(ValueCodec::encodeArgument(...), $this->value);
        }

        return ValueCodec::encodeArgument($this->value);
    }

    /**
     * @param  list<Filter>  $filters
     */
    private static function composite(string $type, array $filters): self
    {
        if ($filters === []) {
            throw new InvalidArgumentException("An [{$type}] filter needs at least one filter.");
        }

        return new self(composite: $type, filters: array_values($filters));
    }
}
