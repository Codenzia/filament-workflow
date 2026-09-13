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
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestUser;
use Codenzia\FilamentWorkflow\Triggers\ModelCreatedTrigger;

beforeEach(function (): void {
    config()->set('auth.providers.users.model', TestUser::class);

    WorkflowEngine::clearRegistrations();
    WorkflowEngine::registerTrigger('model.created', ModelCreatedTrigger::class);
    WorkflowEngine::registerAction('change_field', ChangeFieldAction::class);
    WorkflowEngine::registerAction('send_notification', SendNotificationAction::class);
    WorkflowEngine::registerModelFields(TestModel::class, ['name' => 'Name', 'status' => 'Status']);
});

/**
 * trigger → first action → second action.
 */
function twoActionWorkflow(array $firstConfig, string $firstType = 'change_field'): Workflow
{
    $workflow = Workflow::create([
        'name' => 'Outcome',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $first = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => $firstType,
        'config' => $firstConfig,
    ]);

    $second = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
        'config' => ['field' => 'status', 'value' => 'downstream'],
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $first->id,
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $first->id,
        'target_node_id' => $second->id,
    ]);

    return $workflow;
}

it('records an action that skipped as skipped, not success', function (): void {
    // 'progress' is a real column but was never registered as writable.
    $workflow = twoActionWorkflow(['field' => 'progress', 'value' => 50]);

    $model = TestModel::create(['name' => 'Original', 'status' => 'active']);

    (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']);

    $results = WorkflowExecutionLog::where('workflow_id', $workflow->id)
        ->orderBy('id')
        ->pluck('result')
        ->all();

    expect($results)->toBe(['success', 'skipped', 'success'])
        ->and($model->fresh()->progress)->toBe(0);
});

it('records an unresolvable notification recipient as skipped', function (): void {
    $workflow = Workflow::create([
        'name' => 'Skip Only',
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
        'type_config' => 'send_notification',
        'config' => ['user_id' => 999],
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $action->id,
    ]);

    $model = TestModel::create(['name' => 'Original']);

    (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']);

    $actionLog = WorkflowExecutionLog::where('workflow_node_id', $action->id)->sole();

    expect($actionLog->result)->toBe('skipped')
        ->and($actionLog->details['reason'])->toBe('User 999 not found');
});

it('records an errored action as an error and stops the branch', function (): void {
    // No field configured: ChangeFieldAction reports an error, not a skip.
    $workflow = twoActionWorkflow(['value' => 'nothing']);

    $model = TestModel::create(['name' => 'Original', 'status' => 'active']);

    (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']);

    $results = WorkflowExecutionLog::where('workflow_id', $workflow->id)
        ->orderBy('id')
        ->pluck('result')
        ->all();

    // trigger, errored action — the downstream node never ran.
    expect($results)->toBe(['success', 'error'])
        ->and($model->fresh()->status)->toBe('active');
});

it('reports a notification node with only a fallback message as skipped', function (): void {
    $user = TestUser::create(['name' => 'Watcher', 'email' => 'watcher@example.test']);

    $workflow = twoActionWorkflow(['user_id' => $user->id, 'message' => 'Fallback only'], 'send_notification');

    $model = TestModel::create(['name' => 'Original', 'status' => 'active']);

    (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']);

    $results = WorkflowExecutionLog::where('workflow_id', $workflow->id)
        ->orderBy('id')
        ->pluck('result')
        ->all();

    expect($results)->toBe(['success', 'skipped', 'success']);
});
