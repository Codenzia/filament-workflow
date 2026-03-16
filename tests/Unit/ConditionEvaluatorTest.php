<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Engine\ConditionEvaluator;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestModel;

beforeEach(function (): void {
    $this->evaluator = new ConditionEvaluator;

    $this->model = TestModel::create([
        'status' => 'active',
        'priority' => 'high',
        'name' => 'Test Task',
        'progress' => 75,
    ]);
});

it('evaluates equals operator correctly', function (): void {
    $conditions = [['field' => 'status', 'operator' => 'equals', 'value' => 'active']];

    expect($this->evaluator->evaluate($this->model, $conditions))->toBeTrue();
});

it('evaluates not_equals operator', function (): void {
    $conditions = [['field' => 'status', 'operator' => 'not_equals', 'value' => 'closed']];

    expect($this->evaluator->evaluate($this->model, $conditions))->toBeTrue();
});

it('evaluates greater_than with numeric values', function (): void {
    $conditions = [['field' => 'progress', 'operator' => 'greater_than', 'value' => 50]];

    expect($this->evaluator->evaluate($this->model, $conditions))->toBeTrue();
});

it('evaluates less_than with numeric values', function (): void {
    $conditions = [['field' => 'progress', 'operator' => 'less_than', 'value' => 100]];

    expect($this->evaluator->evaluate($this->model, $conditions))->toBeTrue();
});

it('evaluates contains operator on strings', function (): void {
    $conditions = [['field' => 'name', 'operator' => 'contains', 'value' => 'Test']];

    expect($this->evaluator->evaluate($this->model, $conditions))->toBeTrue();
});

it('evaluates is_null operator', function (): void {
    $this->model->update(['name' => null]);

    $conditions = [['field' => 'name', 'operator' => 'is_null', 'value' => null]];

    expect($this->evaluator->evaluate($this->model->fresh(), $conditions))->toBeTrue();
});

it('evaluates is_not_null operator', function (): void {
    $conditions = [['field' => 'name', 'operator' => 'is_not_null', 'value' => null]];

    expect($this->evaluator->evaluate($this->model, $conditions))->toBeTrue();
});

it('evaluates in operator with array values', function (): void {
    $conditions = [['field' => 'priority', 'operator' => 'in', 'value' => ['high', 'critical']]];

    expect($this->evaluator->evaluate($this->model, $conditions))->toBeTrue();
});

it('returns true when conditions array is empty', function (): void {
    expect($this->evaluator->evaluate($this->model, []))->toBeTrue();
});

it('applies AND logic — all conditions must match', function (): void {
    $conditions = [
        ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ['field' => 'priority', 'operator' => 'equals', 'value' => 'high'],
    ];

    expect($this->evaluator->evaluate($this->model, $conditions, 'and'))->toBeTrue();

    // One fails
    $conditions[] = ['field' => 'status', 'operator' => 'equals', 'value' => 'closed'];

    expect($this->evaluator->evaluate($this->model, $conditions, 'and'))->toBeFalse();
});

it('applies OR logic — any condition can match', function (): void {
    $conditions = [
        ['field' => 'status', 'operator' => 'equals', 'value' => 'closed'],
        ['field' => 'priority', 'operator' => 'equals', 'value' => 'high'],
    ];

    expect($this->evaluator->evaluate($this->model, $conditions, 'or'))->toBeTrue();
});

it('handles missing model attributes gracefully', function (): void {
    $conditions = [['field' => 'nonexistent_field', 'operator' => 'equals', 'value' => 'something']];

    expect($this->evaluator->evaluate($this->model, $conditions))->toBeFalse();
});

it('returns false for unknown operator', function (): void {
    $conditions = [['field' => 'status', 'operator' => 'unknown_op', 'value' => 'active']];

    expect($this->evaluator->evaluate($this->model, $conditions))->toBeFalse();
});
