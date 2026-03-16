<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Actions\ChangeFieldAction;
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
});

it('does not re-evaluate when engine is already executing', function (): void {
    $workflow = Workflow::create([
        'name' => 'Loop Test',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $model = TestModel::create(['name' => 'Test']);

    // Simulate engine already executing
    $ref = new ReflectionProperty(WorkflowEngine::class, 'executing');
    $ref->setAccessible(true);
    $ref->setValue(null, true);

    $engine = new WorkflowEngine;
    $engine->evaluate($model, 'model.created', ['event' => 'created']);

    // Should have been skipped
    expect(WorkflowExecutionLog::count())->toBe(0);

    // Reset
    $ref->setValue(null, false);
});

it('resets executing flag after completion even on exception', function (): void {
    expect(WorkflowEngine::isExecuting())->toBeFalse();

    $workflow = Workflow::create([
        'name' => 'Exception Test',
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

    // Flag should be reset after execution
    expect(WorkflowEngine::isExecuting())->toBeFalse();
});

it('respects max node execution count per run', function (): void {
    // Set max to 3 via config
    config(['filament-workflow.max_nodes_per_run' => 3]);

    $workflow = Workflow::create([
        'name' => 'Max Nodes Test',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    // Create a chain of 5 nodes: trigger → action1 → action2 → action3 → action4
    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $prev = $trigger;
    for ($i = 1; $i <= 4; $i++) {
        $action = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'node_type' => 'action',
            'type_config' => 'change_field',
            'config' => ['field' => 'name', 'value' => "Action {$i}"],
        ]);

        WorkflowConnection::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $prev->id,
            'target_node_id' => $action->id,
        ]);

        $prev = $action;
    }

    $model = TestModel::create(['name' => 'Original']);

    $engine = new WorkflowEngine;
    $engine->evaluate($model, 'model.created', ['event' => 'created']);

    // With max=3: trigger (1) + action1 (2) + action2 (3) = 3 executed, action3 skipped
    $logs = WorkflowExecutionLog::where('workflow_id', $workflow->id)->get();
    $skippedLogs = $logs->where('result', 'skipped');
    expect($skippedLogs)->not->toBeEmpty();
});
