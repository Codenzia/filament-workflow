<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Actions\ChangeFieldAction;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Jobs\EvaluateWorkflowJob;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestModel;
use Codenzia\FilamentWorkflow\Tests\Fixtures\WatchedModel;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    WorkflowEngine::clearRegistrations();
});

it('does not lend one model the fields registered for another', function (): void {
    WorkflowEngine::registerModelFields(TestModel::class, ['status' => 'Status']);

    expect(WorkflowEngine::getModelFields(WatchedModel::class))->toBe([])
        // The designer-wide catalog still lists everything registered.
        ->and(WorkflowEngine::getModelFields())->toBe(['status' => 'Status']);
});

it('refuses to write a field registered on a different model', function (): void {
    WorkflowEngine::registerModelFields(TestModel::class, ['status' => 'Status']);

    $model = WatchedModel::create(['name' => 'Original', 'status' => 'active']);

    $result = (new ChangeFieldAction)->execute($model, ['field' => 'status', 'value' => 'hijacked'], []);

    expect($result['skipped'] ?? false)->toBeTrue()
        ->and($model->fresh()->status)->toBe('active');
});

it('dispatches nothing for an update on a model with no registered fields', function (): void {
    $model = WatchedModel::create(['name' => 'Original', 'status' => 'active']);

    Queue::fake();

    $model->update(['status' => 'changed']);

    Queue::assertNotPushed(EvaluateWorkflowJob::class);
});

it('serialises only registered fields into the event payload', function (): void {
    WorkflowEngine::registerModelFields(WatchedModel::class, ['status' => 'Status']);

    $model = WatchedModel::create(['name' => 'Original', 'status' => 'active']);

    Queue::fake();

    $model->update(['status' => 'changed', 'name' => 'Secret']);

    Queue::assertPushed(EvaluateWorkflowJob::class, function (EvaluateWorkflowJob $job): bool {
        $context = (new ReflectionProperty($job, 'context'))->getValue($job);

        return $context['changed_fields'] === ['status']
            && ! array_key_exists('name', $context['new']);
    });
});
