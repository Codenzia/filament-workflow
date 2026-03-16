<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Actions\ChangeFieldAction;
use Codenzia\FilamentWorkflow\Actions\SendNotificationAction;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowConnection;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestModel;
use Codenzia\FilamentWorkflow\Triggers\ModelCreatedTrigger;

beforeEach(function (): void {
    WorkflowEngine::clearRegistrations();
    WorkflowEngine::registerTrigger('model.created', ModelCreatedTrigger::class);
    WorkflowEngine::registerAction('change_field', ChangeFieldAction::class);
    WorkflowEngine::registerAction('send_notification', SendNotificationAction::class);
});

it('executes a complete trigger → action flow', function (): void {
    $workflow = Workflow::create([
        'name' => 'Test Flow',
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
        'config' => ['field' => 'name', 'value' => 'Automated'],
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $action->id,
    ]);

    $model = TestModel::create(['name' => 'Original']);

    $engine = new WorkflowEngine;
    $engine->evaluate($model, 'model.created', ['event' => 'created']);

    $model->refresh();
    expect($model->name)->toBe('Automated');
});

it('logs successful execution with details', function (): void {
    $workflow = Workflow::create([
        'name' => 'Log Test',
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
        'config' => ['field' => 'name', 'value' => 'Changed'],
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $action->id,
    ]);

    $model = TestModel::create(['name' => 'Original']);

    $engine = new WorkflowEngine;
    $engine->evaluate($model, 'model.created', ['event' => 'created']);

    $logs = WorkflowExecutionLog::where('workflow_id', $workflow->id)->get();
    expect($logs)->toHaveCount(2); // trigger + action
    expect($logs->pluck('result')->toArray())->toBe(['success', 'success']);
});

it('increments run_count and sets last_run_at after execution', function (): void {
    $workflow = Workflow::create([
        'name' => 'Run Count Test',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $model = TestModel::create(['name' => 'Test']);

    $engine = new WorkflowEngine;
    $engine->evaluate($model, 'model.created', ['event' => 'created']);

    $workflow->refresh();
    expect($workflow->run_count)->toBe(1);
    expect($workflow->last_run_at)->not->toBeNull();
});

it('skips inactive workflows', function (): void {
    $workflow = Workflow::create([
        'name' => 'Inactive',
        'model_type' => TestModel::class,
        'status' => 'inactive',
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $model = TestModel::create(['name' => 'Test']);

    $engine = new WorkflowEngine;
    $engine->evaluate($model, 'model.created', ['event' => 'created']);

    expect(WorkflowExecutionLog::count())->toBe(0);
});
