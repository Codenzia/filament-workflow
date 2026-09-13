<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Jobs\EvaluateWorkflowJob;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Codenzia\FilamentWorkflow\Tests\Fixtures\DueTimeTrigger;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestModel;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    WorkflowEngine::clearRegistrations();
});

it('exits successfully when a workflow has an unregistered time trigger', function (): void {
    $workflow = Workflow::create([
        'name' => 'Unregistered Time Trigger',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'time.unregistered',
    ]);

    $this->artisan('workflow:process-time-triggers')
        ->assertExitCode(0)
        ->expectsOutputToContain('Processed 0 time-based triggers.');
});

it('prunes execution logs older than the retention window', function (): void {
    config()->set('filament-workflow.log_retention_days', 90);

    $workflow = Workflow::create([
        'name' => 'Retention',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    $old = WorkflowExecutionLog::create([
        'workflow_id' => $workflow->id,
        'model_type' => TestModel::class,
        'model_id' => 1,
        'trigger_type' => 'model.created',
        'result' => 'success',
        'executed_at' => now()->subDays(120),
    ]);

    $recent = WorkflowExecutionLog::create([
        'workflow_id' => $workflow->id,
        'model_type' => TestModel::class,
        'model_id' => 2,
        'trigger_type' => 'model.created',
        'result' => 'success',
        'executed_at' => now()->subDays(1),
    ]);

    $this->artisan('workflow:process-time-triggers')->assertExitCode(0);

    expect(WorkflowExecutionLog::find($old->id))->toBeNull();
    expect(WorkflowExecutionLog::find($recent->id))->not->toBeNull();
});

it('does not prune when retention is null', function (): void {
    config()->set('filament-workflow.log_retention_days', null);

    $workflow = Workflow::create([
        'name' => 'No Retention',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    $old = WorkflowExecutionLog::create([
        'workflow_id' => $workflow->id,
        'model_type' => TestModel::class,
        'model_id' => 1,
        'trigger_type' => 'model.created',
        'result' => 'success',
        'executed_at' => now()->subDays(500),
    ]);

    $this->artisan('workflow:process-time-triggers')->assertExitCode(0);

    expect(WorkflowExecutionLog::find($old->id))->not->toBeNull();
});

/**
 * A workflow whose only trigger node is the registered due-time trigger.
 */
function dueTimeWorkflow(string $name): Workflow
{
    $workflow = Workflow::create([
        'name' => $name,
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'time.due',
    ]);

    return $workflow;
}

it('dispatches a run scoped to the workflow whose trigger came due', function (): void {
    WorkflowEngine::registerTrigger('time.due', DueTimeTrigger::class);

    $first = dueTimeWorkflow('First');
    $second = dueTimeWorkflow('Second');

    $model = TestModel::create(['name' => 'Due', 'status' => 'due']);

    Queue::fake();

    $this->artisan('workflow:process-time-triggers')->assertExitCode(0);

    Queue::assertPushed(EvaluateWorkflowJob::class, 2);

    $dispatchedFor = Queue::pushed(EvaluateWorkflowJob::class)
        ->map(fn (EvaluateWorkflowJob $job) => (new ReflectionProperty($job, 'workflowId'))->getValue($job))
        ->sort()
        ->values()
        ->all();

    expect($dispatchedFor)->toBe([$first->id, $second->id]);
});

it('suppresses a re-dispatch only after a run the engine accepted', function (): void {
    WorkflowEngine::registerTrigger('time.due', DueTimeTrigger::class);

    $workflow = dueTimeWorkflow('Accepted');
    $model = TestModel::create(['name' => 'Due', 'status' => 'due']);

    WorkflowExecutionLog::create([
        'workflow_id' => $workflow->id,
        'model_type' => TestModel::class,
        'model_id' => $model->getKey(),
        'trigger_type' => 'time.due',
        'result' => 'success',
        'executed_at' => now()->subHour(),
    ]);

    Queue::fake();

    $this->artisan('workflow:process-time-triggers')->assertExitCode(0);

    Queue::assertNotPushed(EvaluateWorkflowJob::class);
});

it('still dispatches when the only recent log is a skipped attempt', function (): void {
    WorkflowEngine::registerTrigger('time.due', DueTimeTrigger::class);

    $workflow = dueTimeWorkflow('Skipped Before');
    $model = TestModel::create(['name' => 'Due', 'status' => 'due']);

    WorkflowExecutionLog::create([
        'workflow_id' => $workflow->id,
        'model_type' => TestModel::class,
        'model_id' => $model->getKey(),
        'trigger_type' => 'time.due',
        'result' => 'skipped',
        'executed_at' => now()->subHour(),
    ]);

    Queue::fake();

    $this->artisan('workflow:process-time-triggers')->assertExitCode(0);

    Queue::assertPushed(EvaluateWorkflowJob::class, 1);
});
