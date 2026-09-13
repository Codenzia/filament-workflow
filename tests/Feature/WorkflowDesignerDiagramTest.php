<?php

declare(strict_types=1);

use Codenzia\FilamentWorkflow\Enums\NodeTypeEnum;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Codenzia\FilamentWorkflow\NodeTypes\ActionNodeType;
use Codenzia\FilamentWorkflow\NodeTypes\ConditionNodeType;
use Codenzia\FilamentWorkflow\NodeTypes\DelayNodeType;
use Codenzia\FilamentWorkflow\NodeTypes\TriggerNodeType;
use Codenzia\FilamentWorkflow\Pages\WorkflowDesigner;

/**
 * The diagrammer fails closed, so the designer must supply its own edit
 * policy and register the workflow node types it draws with.
 */
function designer(bool $canEdit, ?int $workflowId): WorkflowDesigner
{
    $page = new class extends WorkflowDesigner
    {
        public bool $editable = false;

        public function canEditWorkflow(): bool
        {
            return $this->editable;
        }
    };

    $page->editable = $canEdit;
    $page->selectedWorkflowId = $workflowId;

    return $page;
}

function callDesigner(WorkflowDesigner $page, string $method, array $args = []): mixed
{
    $reflection = new ReflectionMethod($page, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($page, $args);
}

it('allows diagram edits when the workflow may be edited', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\Models\Task']);

    expect(callDesigner(designer(true, $workflow->id), 'canEditDiagram'))->toBeTrue();
});

it('denies diagram edits when the workflow may not be edited', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\Models\Task']);

    expect(callDesigner(designer(false, $workflow->id), 'canEditDiagram'))->toBeFalse();
});

it('denies diagram edits when no workflow is selected', function (): void {
    expect(callDesigner(designer(true, null), 'canEditDiagram'))->toBeFalse();
});

it('registers every workflow node type in the palette', function (): void {
    $canvas = callDesigner(designer(true, null), 'getDiagramCanvas');

    expect($canvas->getPaletteNodeTypes())->toBe([
        TriggerNodeType::class,
        ConditionNodeType::class,
        DelayNodeType::class,
        ActionNodeType::class,
    ]);
});

it('persists a duplicated node that reports no node type', function (): void {
    $workflow = Workflow::create(['name' => 'Test', 'model_type' => 'App\Models\Task']);

    callDesigner(
        designer(true, $workflow->id),
        'persistNodeCreate',
        ['wf-node-1-copy-abc', null, 120.0, 240.0],
    );

    $node = WorkflowNode::where('workflow_id', $workflow->id)->sole();

    expect($node->node_type)->toBe(NodeTypeEnum::ACTION)
        ->and($node->position_x)->toEqual(120.0)
        ->and($node->position_y)->toEqual(240.0);
});
