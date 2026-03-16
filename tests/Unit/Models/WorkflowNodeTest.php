<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Enums\NodeTypeEnum;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowConnection;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;

it('belongs to a workflow', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\\Models\\Task']);

    $node = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    expect($node->workflow->id)->toBe($workflow->id);
});

it('casts node_type to enum', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\\Models\\Task']);

    $node = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'condition',
        'config' => ['conditions' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']]],
    ]);

    expect($node->node_type)->toBe(NodeTypeEnum::CONDITION);
});

it('can get config values', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\\Models\\Task']);

    $node = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'delay',
        'config' => ['duration' => 5, 'unit' => 'hours'],
    ]);

    expect($node->getConfigValue('duration'))->toBe(5);
    expect($node->getConfigValue('unit'))->toBe('hours');
    expect($node->getConfigValue('missing', 'default'))->toBe('default');
});

it('can get next nodes via outgoing connections', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\\Models\\Task']);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $action1 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
    ]);

    $action2 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'send_notification',
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $action1->id,
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $action2->id,
    ]);

    $nextNodes = $trigger->getNextNodes();

    expect($nextNodes)->toHaveCount(2);
});

it('can filter next nodes by connection label', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\\Models\\Task']);

    $condition = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'condition',
    ]);

    $yesAction = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'yes_action',
    ]);

    $noAction = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'no_action',
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

    $yesNodes = $condition->getNextNodes('Yes');
    $noNodes = $condition->getNextNodes('No');

    expect($yesNodes)->toHaveCount(1);
    expect($yesNodes->first()->type_config)->toBe('yes_action');
    expect($noNodes)->toHaveCount(1);
    expect($noNodes->first()->type_config)->toBe('no_action');
});
