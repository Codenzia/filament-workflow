<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Actions\ChangeFieldAction;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Jobs\ExecuteDelayedNodeJob;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowConnection;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestModel;
use Codenzia\FilamentWorkflow\Triggers\ModelCreatedTrigger;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    WorkflowEngine::clearRegistrations();
    WorkflowEngine::registerTrigger('model.created', ModelCreatedTrigger::class);
    WorkflowEngine::registerAction('change_field', ChangeFieldAction::class);
    WorkflowEngine::registerModelFields(TestModel::class, ['name' => 'Name']);
});

/**
 * Build trigger → action → delay → (back to action), the cycle the engine
 * could not see before because a delayed resume started a brand new run.
 */
function cyclicDelayWorkflow(): array
{
    $workflow = Workflow::create([
        'name' => 'Delayed Cycle',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $action = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
        'config' => ['field' => 'name', 'value' => 'Looped'],
    ]);

    $delay = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'delay',
        'config' => ['duration' => 1, 'unit' => 'minutes'],
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $action->id,
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $action->id,
        'target_node_id' => $delay->id,
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $delay->id,
        'target_node_id' => $action->id,
    ]);

    return [$workflow, $trigger, $action, $delay];
}

it('carries the run identity into the delayed continuation', function (): void {
    Queue::fake();

    [$workflow, $trigger, $action, $delay] = cyclicDelayWorkflow();

    $model = TestModel::create(['name' => 'Original']);

    (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']);

    Queue::assertPushed(ExecuteDelayedNodeJob::class, 1);

    $runIds = WorkflowExecutionLog::where('workflow_id', $workflow->id)
        ->get()
        ->map(fn (WorkflowExecutionLog $log): mixed => $log->details['run_id'] ?? null)
        ->unique();

    expect($runIds)->toHaveCount(1)
        ->and($runIds->first())->not->toBeNull();
});

it('stops a cycle that passes through a delay node', function (): void {
    Queue::fake();

    [$workflow, $trigger, $action, $delay] = cyclicDelayWorkflow();

    $model = TestModel::create(['name' => 'Original']);

    (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']);

    // Run the queued continuation exactly as the worker would.
    $continuation = Queue::pushed(ExecuteDelayedNodeJob::class)->first();
    $continuation->handle(app(WorkflowEngine::class));

    $resumeLogs = WorkflowExecutionLog::where('workflow_id', $workflow->id)
        ->where('trigger_type', 'delayed_resume')
        ->get();

    expect($resumeLogs)->toHaveCount(1)
        ->and($resumeLogs->first()->result)->toBe('skipped')
        ->and($resumeLogs->first()->details['reason'])->toContain('Cycle detected');

    // The cycle stopped here: no further continuation was scheduled.
    Queue::assertPushed(ExecuteDelayedNodeJob::class, 1);
});

it('ends a run that exceeds the configured hop limit', function (): void {
    config(['filament-workflow.max_run_hops' => 2]);

    [$workflow, $trigger, $action, $delay] = cyclicDelayWorkflow();

    $model = TestModel::create(['name' => 'Original']);

    (new WorkflowEngine)->executeNodeById($action->id, TestModel::class, (int) $model->getKey(), [], [
        'id' => 'run-over-budget',
        'started_at' => now()->toIso8601String(),
        'hop' => 3,
        'visited' => [$trigger->id],
    ]);

    $log = WorkflowExecutionLog::where('workflow_id', $workflow->id)->sole();

    expect($log->result)->toBe('skipped')
        ->and($log->details['reason'])->toContain('Max delayed continuations')
        ->and($model->fresh()->name)->toBe('Original');
});

it('ends a run that has outlived the maximum run duration', function (): void {
    config(['filament-workflow.max_run_days' => 30]);

    [$workflow, $trigger, $action, $delay] = cyclicDelayWorkflow();

    $model = TestModel::create(['name' => 'Original']);

    (new WorkflowEngine)->executeNodeById($action->id, TestModel::class, (int) $model->getKey(), [], [
        'id' => 'run-too-old',
        'started_at' => now()->subDays(31)->toIso8601String(),
        'hop' => 1,
        'visited' => [$trigger->id],
    ]);

    $log = WorkflowExecutionLog::where('workflow_id', $workflow->id)->sole();

    expect($log->result)->toBe('skipped')
        ->and($log->details['reason'])->toContain('Max run duration')
        ->and($model->fresh()->name)->toBe('Original');
});
