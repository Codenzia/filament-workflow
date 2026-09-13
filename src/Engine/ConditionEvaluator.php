<?php

declare(strict_types=1);

/**
 * ConditionEvaluator
 *
 * Evaluates condition node expressions against model attributes.
 * Supports multiple operators, AND/OR logic, and dot-notation
 * for relationship traversal.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Engine;

use Codenzia\FilamentWorkflow\Enums\ConditionOperatorEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ConditionEvaluator
{
    /**
     * Evaluate a set of conditions against a model.
     *
     * @param  Model  $model  The model to evaluate against
     * @param  array  $conditions  Array of condition definitions, each with 'field', 'operator', 'value'
     * @param  string  $logic  'and' or 'or' — how to combine multiple conditions
     * @return bool Whether the conditions are satisfied
     */
    public function evaluate(Model $model, array $conditions, string $logic = 'and'): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $result = $this->evaluateCondition($model, $condition);

            if ($logic === 'or' && $result) {
                return true;
            }

            if ($logic === 'and' && ! $result) {
                return false;
            }
        }

        // AND: all passed → true. OR: none passed → false.
        return $logic === 'and';
    }

    /**
     * Evaluate a single condition against a model.
     */
    protected function evaluateCondition(Model $model, array $condition): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $expected = $condition['value'] ?? null;

        if ($field === null) {
            return false;
        }

        $actual = $this->resolveFieldValue($model, $field);
        $operatorEnum = ConditionOperatorEnum::tryFrom($operator);

        if ($operatorEnum === null) {
            return false;
        }

        return $this->compare($actual, $operatorEnum, $expected);
    }

    /**
     * Resolve a field value from a model, supporting dot-notation for relationships.
     * e.g., 'project.status' resolves $model->project->status
     */
    protected function resolveFieldValue(Model $model, string $field): mixed
    {
        if (! str_contains($field, '.')) {
            return $model->getAttribute($field);
        }

        return data_get($model, $field);
    }

    /**
     * Compare an actual value against an expected value using an operator.
     *
     * Numeric comparisons (>, <, >=, <=) try numeric semantics first, then
     * fall back to date semantics if both operands look like dates. If
     * neither path applies, the operator is logged as indeterminate and
     * returns false (so the condition is treated as not-met).
     */
    protected function compare(mixed $actual, ConditionOperatorEnum $operator, mixed $expected): bool
    {
        return match ($operator) {
            ConditionOperatorEnum::EQUALS => $actual == $expected,
            ConditionOperatorEnum::NOT_EQUALS => $actual != $expected,
            ConditionOperatorEnum::GREATER_THAN => $this->compareOrdered($actual, $expected, fn ($a, $b) => $a > $b, '>'),
            ConditionOperatorEnum::LESS_THAN => $this->compareOrdered($actual, $expected, fn ($a, $b) => $a < $b, '<'),
            ConditionOperatorEnum::GREATER_THAN_OR_EQUAL => $this->compareOrdered($actual, $expected, fn ($a, $b) => $a >= $b, '>='),
            ConditionOperatorEnum::LESS_THAN_OR_EQUAL => $this->compareOrdered($actual, $expected, fn ($a, $b) => $a <= $b, '<='),
            ConditionOperatorEnum::CONTAINS => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            ConditionOperatorEnum::NOT_CONTAINS => is_string($actual) && is_string($expected) && ! str_contains($actual, $expected),
            ConditionOperatorEnum::IS_NULL => $actual === null || $actual === '',
            ConditionOperatorEnum::IS_NOT_NULL => $actual !== null && $actual !== '',
            ConditionOperatorEnum::IN => is_array($expected) && in_array($actual, $expected),
            ConditionOperatorEnum::NOT_IN => is_array($expected) && ! in_array($actual, $expected),
        };
    }

    /**
     * Apply an ordered comparison ($a OP $b) trying numeric semantics first,
     * then date semantics, before giving up. The "give up" path logs an
     * indeterminate-comparison warning so the user can see WHY their
     * condition node behaved as if not-met.
     */
    protected function compareOrdered(mixed $actual, mixed $expected, callable $op, string $opLabel): bool
    {
        // Carbon objects compare correctly via PHP's <, >, etc. (DateTime semantics).
        if ($actual instanceof \DateTimeInterface || $expected instanceof \DateTimeInterface) {
            $a = $this->toCarbon($actual);
            $b = $this->toCarbon($expected);
            if ($a !== null && $b !== null) {
                return $op($a->getTimestamp(), $b->getTimestamp());
            }
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return $op($actual + 0, $expected + 0);
        }

        // Both look like dates? Compare as timestamps.
        if ($this->looksLikeDate($actual) && $this->looksLikeDate($expected)) {
            $a = $this->toCarbon($actual);
            $b = $this->toCarbon($expected);
            if ($a !== null && $b !== null) {
                return $op($a->getTimestamp(), $b->getTimestamp());
            }
        }

        // Indeterminate — log so the user can see why the branch didn't match.
        Log::warning(sprintf(
            'filament-workflow: indeterminate comparison %s %s %s — operands not numeric or dates; treating as not-met',
            $this->stringifyForLog($actual),
            $opLabel,
            $this->stringifyForLog($expected),
        ));

        return false;
    }

    protected function looksLikeDate(mixed $value): bool
    {
        if ($value instanceof \DateTimeInterface) {
            return true;
        }
        if (! is_string($value) || $value === '') {
            return false;
        }

        // Cheap heuristic: ISO-ish (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS) or
        // common slash form. Avoids false positives like "12345" or "abc".
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $value)
            || (bool) preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value);
    }

    protected function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($value));
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function stringifyForLog(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return get_debug_type($value);
    }
}
