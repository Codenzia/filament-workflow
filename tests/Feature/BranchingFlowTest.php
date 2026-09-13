<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Actions\ChangeFieldAction;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowConnection;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestModel;
use Codenzia\FilamentWorkflow\Triggers\ModelCreatedTrigger;

beforeEach(function (): void {
    WorkflowEngine::clearRegistrations();
    WorkflowEngine::registerTrigger('model.created', ModelCreatedTrigger::class);
    WorkflowEngine::registerAction('change_field', ChangeFieldAction::class);
    WorkflowEngine::registerModelFields(TestModel::class, ['name' => 'Name']);
});

it('follows YES path when condition matches', function (): void {
    $workflow = Workflow::create([
        'name' => 'Branching Test',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $condition = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'condition',
        'config' => [
            'conditions' => [
                ['field' => 'name', 'operator' => 'equals', 'value' => 'MatchMe'],
            ],
        ],
    ]);

    $yesAction = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
        'config' => ['field' => 'name', 'value' => 'YES branch'],
    ]);

    $noAction = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
        'config' => ['field' => 'name', 'value' => 'NO branch'],
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $condition->id,
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $condition->id,
        'target_node_id' => $yesAction->id,
        'label' => 'Yes',
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $condition->id,
        'target_node_id' => $noAction->id,
        'label' => 'No',
    ]);

    $model = TestModel::create(['name' => 'MatchMe']);

    $engine = new WorkflowEngine;
    $engine->evaluate($model, 'model.created', ['event' => 'created']);

    $model->refresh();
    expect($model->name)->toBe('YES branch');
});

it('follows NO path when condition does not match', function (): void {
    $workflow = Workflow::create([
        'name' => 'Branching Test',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $condition = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'condition',
        'config' => [
            'conditions' => [
                ['field' => 'name', 'operator' => 'equals', 'value' => 'WontMatch'],
            ],
        ],
    ]);

    $yesAction = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
        'config' => ['field' => 'name', 'value' => 'YES branch'],
    ]);

    $noAction = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
        'config' => ['field' => 'name', 'value' => 'NO branch'],
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $condition->id,
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $condition->id,
        'target_node_id' => $yesAction->id,
        'label' => 'Yes',
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $condition->id,
        'target_node_id' => $noAction->id,
        'label' => 'No',
    ]);

    $model = TestModel::create(['name' => 'SomethingElse']);

    $engine = new WorkflowEngine;
    $engine->evaluate($model, 'model.created', ['event' => 'created']);

    $model->refresh();
    expect($model->name)->toBe('NO branch');
});
