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

class ConditionEvaluator
{
    /**
     * Evaluate a set of conditions against a model.
     *
     * @param  Model  $model       The model to evaluate against
     * @param  array  $conditions  Array of condition definitions, each with 'field', 'operator', 'value'
     * @param  string $logic       'and' or 'or' — how to combine multiple conditions
     * @return bool   Whether the conditions are satisfied
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
     */
    protected function compare(mixed $actual, ConditionOperatorEnum $operator, mixed $expected): bool
    {
        return match ($operator) {
            ConditionOperatorEnum::EQUALS => $actual == $expected,
            ConditionOperatorEnum::NOT_EQUALS => $actual != $expected,
            ConditionOperatorEnum::GREATER_THAN => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            ConditionOperatorEnum::LESS_THAN => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            ConditionOperatorEnum::GREATER_THAN_OR_EQUAL => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            ConditionOperatorEnum::LESS_THAN_OR_EQUAL => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            ConditionOperatorEnum::CONTAINS => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            ConditionOperatorEnum::NOT_CONTAINS => is_string($actual) && is_string($expected) && ! str_contains($actual, $expected),
            ConditionOperatorEnum::IS_NULL => $actual === null || $actual === '',
            ConditionOperatorEnum::IS_NOT_NULL => $actual !== null && $actual !== '',
            ConditionOperatorEnum::IN => is_array($expected) && in_array($actual, $expected),
            ConditionOperatorEnum::NOT_IN => is_array($expected) && ! in_array($actual, $expected),
        };
    }
}
