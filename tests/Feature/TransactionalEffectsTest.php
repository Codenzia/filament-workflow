<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Actions\ChangeFieldAction;
use Codenzia\FilamentWorkflow\Actions\SendNotificationAction;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowConnection;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestModel;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestNotification;
use Codenzia\FilamentWorkflow\Tests\Fixtures\TestUser;
use Codenzia\FilamentWorkflow\Triggers\ModelCreatedTrigger;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('auth.providers.users.model', TestUser::class);

    WorkflowEngine::clearRegistrations();
    WorkflowEngine::registerTrigger('model.created', ModelCreatedTrigger::class);
    WorkflowEngine::registerAction('change_field', ChangeFieldAction::class);
    WorkflowEngine::registerAction('send_notification', SendNotificationAction::class);
    WorkflowEngine::registerNotificationClass(TestNotification::class);
    WorkflowEngine::registerModelFields(TestModel::class, ['name' => 'Name', 'status' => 'Status']);
});

/**
 * trigger → send_notification → [optional trailing node].
 */
function notifyingWorkflow(TestUser $user, ?string $trailingActionType = null): Workflow
{
    $workflow = Workflow::create([
        'name' => 'Notify',
        'model_type' => TestModel::class,
        'status' => 'active',
    ]);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $notify = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'node_type' => 'action',
        'type_config' => 'send_notification',
        'config' => [
            'user_id' => $user->id,
            'notification_class' => TestNotification::class,
        ],
    ]);

    WorkflowConnection::create([
        'workflow_id' => $workflow->id,
        'source_node_id' => $trigger->id,
        'target_node_id' => $notify->id,
    ]);

    if ($trailingActionType !== null) {
        $trailing = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'node_type' => 'action',
            'type_config' => $trailingActionType,
        ]);

        WorkflowConnection::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $notify->id,
            'target_node_id' => $trailing->id,
        ]);
    }

    return $workflow;
}

it('sends a notification when the run commits', function (): void {
    Notification::fake();

    $user = TestUser::create(['name' => 'Approver', 'email' => 'approver@example.test']);
    notifyingWorkflow($user);

    $model = TestModel::create(['name' => 'Original']);

    (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']);

    Notification::assertSentTo($user, TestNotification::class);
});

it('does not send a notification when a later action rolls the run back', function (): void {
    Notification::fake();

    $user = TestUser::create(['name' => 'Approver', 'email' => 'approver@example.test']);

    // 'boom' is not a registered action: the node fails and the run rolls back.
    notifyingWorkflow($user, 'boom');

    $model = TestModel::create(['name' => 'Original']);

    (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']);

    Notification::assertNothingSent();
});

it('discards in-memory writes from a rolled-back run before the next workflow evaluates', function (): void {
    // Higher priority: writes the model, then fails and rolls back.
    $failing = Workflow::create([
        'name' => 'Rolls Back',
        'model_type' => TestModel::class,
        'status' => 'active',
        'priority' => 10,
    ]);

    $failingTrigger = WorkflowNode::create([
        'workflow_id' => $failing->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $write = WorkflowNode::create([
        'workflow_id' => $failing->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
        'config' => ['field' => 'name', 'value' => 'Rolled back'],
    ]);

    $boom = WorkflowNode::create([
        'workflow_id' => $failing->id,
        'node_type' => 'action',
        'type_config' => 'boom',
    ]);

    WorkflowConnection::create([
        'workflow_id' => $failing->id,
        'source_node_id' => $failingTrigger->id,
        'target_node_id' => $write->id,
    ]);

    WorkflowConnection::create([
        'workflow_id' => $failing->id,
        'source_node_id' => $write->id,
        'target_node_id' => $boom->id,
    ]);

    // Lower priority: branches on the value the first workflow never committed.
    $second = Workflow::create([
        'name' => 'Reads After',
        'model_type' => TestModel::class,
        'status' => 'active',
        'priority' => 1,
    ]);

    $secondTrigger = WorkflowNode::create([
        'workflow_id' => $second->id,
        'node_type' => 'trigger',
        'type_config' => 'model.created',
    ]);

    $condition = WorkflowNode::create([
        'workflow_id' => $second->id,
        'node_type' => 'condition',
        'config' => [
            'conditions' => [
                ['field' => 'name', 'operator' => 'equals', 'value' => 'Rolled back'],
            ],
        ],
    ]);

    $leak = WorkflowNode::create([
        'workflow_id' => $second->id,
        'node_type' => 'action',
        'type_config' => 'change_field',
        'config' => ['field' => 'status', 'value' => 'leaked'],
    ]);

    WorkflowConnection::create([
        'workflow_id' => $second->id,
        'source_node_id' => $secondTrigger->id,
        'target_node_id' => $condition->id,
    ]);

    WorkflowConnection::create([
        'workflow_id' => $second->id,
        'source_node_id' => $condition->id,
        'target_node_id' => $leak->id,
        'label' => 'Yes',
    ]);

    $model = TestModel::create(['name' => 'Original', 'status' => 'active']);

    (new WorkflowEngine)->evaluate($model, 'model.created', ['event' => 'created']);

    expect($model->name)->toBe('Original')
        ->and($model->fresh()->name)->toBe('Original')
        ->and($model->fresh()->status)->toBe('active');
});
