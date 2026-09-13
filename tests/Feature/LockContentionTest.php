<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Exceptions\WorkflowLockTimeoutException;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestModel;
use Codenzia\FilamentWorkflow\Triggers\ModelCreatedTrigger;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    WorkflowEngine::clearRegistrations();
    WorkflowEngine::registerTrigger('model.created', ModelCreatedTrigger::class);
    config()->set('filament-workflow.lock_wait_seconds', 0);
});

/**
 * SQLite and the array cache driver cannot prove production locking. These
 * cover the contract the queued jobs rely on: contention surfaces instead of
 * being swallowed, so the job can put the work back on the queue.
 */
it('reports lock contention instead of dropping the event', function (): void {
    $workflow = Workflow::create([
        'name' => 'Contended',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $model = TestModel::create(['name' => 'Original']);

    $held = Cache::lock('filament-workflow:lock:'.md5(TestModel::class.':'.$model->getKey()), 60);
    expect($held->get())->toBeTrue();

    try {
        expect(fn () => (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']))
            ->toThrow(WorkflowLockTimeoutException::class);

        expect(WorkflowExecutionLog::count())->toBe(0);
    } finally {
        $held->release();
    }
});

it('reports lock contention on a delayed resume', function (): void {
    $workflow = Workflow::create([
        'name' => 'Contended Resume',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    $node = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $model = TestModel::create(['name' => 'Original']);

    $held = Cache::lock('filament-workflow:lock:'.md5(TestModel::class.':'.$model->getKey()), 60);
    expect($held->get())->toBeTrue();

    try {
        expect(fn () => (new WorkflowEngine)->executeNodeById($node->id, TestModel::class, (int) $model->getKey(), []))
            ->toThrow(WorkflowLockTimeoutException::class);
    } finally {
        $held->release();
    }
});
