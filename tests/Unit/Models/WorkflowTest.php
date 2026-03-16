<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Enums\WorkflowStatusEnum;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowConnection;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;

it('can create a workflow', function (): void {
    $workflow = Workflow::create([
        'name' => 'Test Workflow',
        'model_type' => 'App\\Models\\Task',
        'status' => 'active',
    ]);

    expect($workflow->name)->toBe('Test Workflow');
    expect($workflow->model_type)->toBe('App\\Models\\Task');
    expect($workflow->status)->toBe(WorkflowStatusEnum::ACTIVE);
    expect($workflow->run_count)->toBe(0);
});

it('has many nodes', function (): void {
    $workflow = Workflow::create([
        'name' => 'Test',
        'model_type' => 'App\\Models\\Task',
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
    ]);

    expect($workflow->nodes)->toHaveCount(2);
});

it('has many connections', function (): void {
    $workflow = Workflow::create([
        'name' => 'Test',
        'model_type' => 'App\\Models\\Task',
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
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $action->id,
    ]);

    expect($workflow->connections)->toHaveCount(1);
});

it('scopes to active workflows', function (): void {
    Workflow::create(['name' => 'Active', 'model_type' => 'App\\Models\\Task', 'status' => 'active']);
    Workflow::create(['name' => 'Draft', 'model_type' => 'App\\Models\\Task', 'status' => 'draft']);
    Workflow::create(['name' => 'Inactive', 'model_type' => 'App\\Models\\Task', 'status' => 'inactive']);

    expect(Workflow::active()->count())->toBe(1);
});

it('scopes to workflows for a specific trigger', function (): void {
    $w1 = Workflow::create(['name' => 'W1', 'model_type' => 'App\\Models\\Task', 'status' => 'active']);
    WorkflowNode::create(['workflow_id' => $w1->id, 'node_type' => 'trigger', 'type_config' => 'model.created']);

    $w2 = Workflow::create(['name' => 'W2', 'model_type' => 'App\\Models\\Task', 'status' => 'active']);
    WorkflowNode::create(['workflow_id' => $w2->id, 'node_type' => 'trigger', 'type_config' => 'model.updated']);

    expect(Workflow::forTrigger('model.created')->count())->toBe(1);
    expect(Workflow::forTrigger('model.updated')->count())->toBe(1);
});

it('scopes to project or global workflows', function (): void {
    // Create projects for FK constraints
    $this->app['db']->connection()->table('projects')->insert(['id' => 1, 'name' => 'P1', 'created_at' => now(), 'updated_at' => now()]);
    $this->app['db']->connection()->table('projects')->insert(['id' => 2, 'name' => 'P2', 'created_at' => now(), 'updated_at' => now()]);

    Workflow::create(['name' => 'Global', 'model_type' => 'App\\Models\\Task', 'project_id' => null]);
    Workflow::create(['name' => 'Project 1', 'model_type' => 'App\\Models\\Task', 'project_id' => 1]);
    Workflow::create(['name' => 'Project 2', 'model_type' => 'App\\Models\\Task', 'project_id' => 2]);

    // For project 1: should include global + project 1
    expect(Workflow::forProject(1)->count())->toBe(2);

    // For null: should include only global
    expect(Workflow::forProject(null)->count())->toBe(1);
});

it('cascades delete to nodes, connections, and logs', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\\Models\\Task']);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $action = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $action->id,
    ]);

    WorkflowExecutionLog::create([
        'workflow_id' => $workflow->id,
        'workflow_node_id' => $trigger->id,
        'model_type' => 'App\\Models\\Task',
        'model_id' => 1,
        'trigger_type' => 'model.created',
        'result' => 'success',
        'executed_at' => now(),
    ]);

    $workflow->delete();

    expect(WorkflowNode::count())->toBe(0);
    expect(WorkflowConnection::count())->toBe(0);
    expect(WorkflowExecutionLog::count())->toBe(0);
});

it('records a run', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\\Models\\Task']);

    expect($workflow->run_count)->toBe(0);
    expect($workflow->last_run_at)->toBeNull();

    $workflow->recordRun();
    $workflow->refresh();

    expect($workflow->run_count)->toBe(1);
    expect($workflow->last_run_at)->not->toBeNull();
});
