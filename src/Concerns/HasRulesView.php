<?php

declare(strict_types=1);

/**
 * HasRulesView - Trait for form-based rules editing of workflows.
 *
 * Provides an alternative to the visual diagram editor where users
 * can build workflows using structured form components. Both views
 * edit the same underlying workflow_nodes/connections tables.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Concerns;

use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Enums\NodeTypeEnum;
use Codenzia\FilamentWorkflow\Models\WorkflowConnection;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

trait HasRulesView
{
    public string $activeTab = 'diagram';

    // ─── Tab Switching ──────────────────────────────────────────────

    public function switchToTab(string $tab): void
    {
        $this->activeTab = $tab;

        if ($tab === 'diagram') {
            $this->computeAutoLayout();
            $this->initializeDiagram();
            $this->refreshDiagram();
        }
    }

    // ─── Rules Tree Builder ─────────────────────────────────────────

    /**
     * Build a nested tree structure from workflow nodes and connections.
     * Returns both connected flows and unconnected orphan nodes.
     *
     * @return array{flows: array, orphans: array<WorkflowNode>}
     */
    public function getRulesTree(): array
    {
        if (! $this->selectedWorkflowId) {
            return ['flows' => [], 'orphans' => []];
        }

        $workflow = $this->findScopedWorkflow($this->selectedWorkflowId)?->load(['nodes', 'connections']);
        if (! $workflow) {
            return ['flows' => [], 'orphans' => []];
        }

        $nodes = $workflow->nodes->keyBy('id');
        $connections = $workflow->connections;

        // Build adjacency: source_id => [{target_id, label}]
        $adjacency = [];
        foreach ($connections as $conn) {
            $adjacency[$conn->source_node_id][] = [
                'target_id' => $conn->target_node_id,
                'label' => $conn->label,
            ];
        }

        // IDs that participate in any connection
        $connectedIds = $connections->pluck('source_node_id')
            ->merge($connections->pluck('target_node_id'))
            ->unique()
            ->all();

        // Find root nodes: triggers first, then connected nodes with no incoming
        $hasIncoming = $connections->pluck('target_node_id')->unique()->all();
        $roots = $nodes->filter(function (WorkflowNode $n) use ($hasIncoming, $connectedIds) {
            // Must be a trigger or a connected node with no incoming
            if ($n->node_type === NodeTypeEnum::TRIGGER) {
                return true;
            }

            return in_array($n->id, $connectedIds) && ! in_array($n->id, $hasIncoming);
        });

        $visited = [];

        $flows = $roots->map(fn (WorkflowNode $node) => $this->buildTreeNode($node, $nodes, $adjacency, $visited))
            ->values()
            ->toArray();

        // Orphans: nodes not visited during tree walk
        $orphans = $nodes->filter(fn (WorkflowNode $n) => ! in_array($n->id, $visited))
            ->values()
            ->all();

        return ['flows' => $flows, 'orphans' => $orphans];
    }

    /**
     * Recursively build a tree node with children.
     */
    protected function buildTreeNode(WorkflowNode $node, $allNodes, array $adjacency, array &$visited): array
    {
        if (in_array($node->id, $visited)) {
            return [
                'node' => $node,
                'children' => [],
                'yes_children' => [],
                'no_children' => [],
                'is_cycle' => true,
            ];
        }

        $visited[] = $node->id;

        $children = [];
        $yesChildren = [];
        $noChildren = [];

        $outgoing = $adjacency[$node->id] ?? [];

        if ($node->node_type === NodeTypeEnum::CONDITION) {
            // Split by Yes/No labels
            foreach ($outgoing as $edge) {
                $targetNode = $allNodes[$edge['target_id']] ?? null;
                if (! $targetNode) {
                    continue;
                }

                $child = $this->buildTreeNode($targetNode, $allNodes, $adjacency, $visited);

                if (strtolower($edge['label'] ?? '') === 'yes') {
                    $yesChildren[] = $child;
                } elseif (strtolower($edge['label'] ?? '') === 'no') {
                    $noChildren[] = $child;
                } else {
                    $children[] = $child;
                }
            }
        } else {
            foreach ($outgoing as $edge) {
                $targetNode = $allNodes[$edge['target_id']] ?? null;
                if (! $targetNode) {
                    continue;
                }

                $children[] = $this->buildTreeNode($targetNode, $allNodes, $adjacency, $visited);
            }
        }

        return [
            'node' => $node,
            'children' => $children,
            'yes_children' => $yesChildren,
            'no_children' => $noChildren,
            'is_cycle' => false,
        ];
    }

    // ─── Step Summary ───────────────────────────────────────────────

    /**
     * Get a human-readable summary for a workflow node.
     * Override to customize descriptions per app.
     */
    public function getStepSummary(WorkflowNode $node): string
    {
        $config = $node->config ?? [];

        return match ($node->node_type) {
            NodeTypeEnum::TRIGGER => $this->getTriggerSummary($node),
            NodeTypeEnum::CONDITION => $this->getConditionSummary($node),
            NodeTypeEnum::DELAY => $this->getDelaySummary($node),
            NodeTypeEnum::ACTION => $this->getActionSummary($node),
        };
    }

    protected function getTriggerSummary(WorkflowNode $node): string
    {
        $triggers = WorkflowEngine::getTriggers();
        $triggerClass = $triggers[$node->type_config] ?? null;

        if ($triggerClass) {
            return $triggerClass::label();
        }

        return $node->type_config ?? __('filament-workflow::rules.unknown-trigger');
    }

    protected function getConditionSummary(WorkflowNode $node): string
    {
        $conditions = $node->config['conditions'] ?? [];

        if (empty($conditions)) {
            return __('filament-workflow::rules.no-conditions');
        }

        $logic = $node->config['logic'] ?? 'and';
        $parts = [];

        foreach (array_slice($conditions, 0, 2) as $c) {
            $field = $c['field'] ?? '?';
            $operator = $c['operator'] ?? 'equals';
            $value = $c['value'] ?? '?';
            $parts[] = "{$field} {$operator} {$value}";
        }

        $summary = implode($logic === 'or' ? ' OR ' : ' AND ', $parts);

        if (count($conditions) > 2) {
            $summary .= ' (+'.(count($conditions) - 2).' more)';
        }

        return $summary;
    }

    protected function getDelaySummary(WorkflowNode $node): string
    {
        $duration = $node->config['duration'] ?? 1;
        $unit = $node->config['unit'] ?? 'hours';

        return __('filament-workflow::rules.delay-summary', [
            'duration' => $duration,
            'unit' => $unit,
        ]);
    }

    protected function getActionSummary(WorkflowNode $node): string
    {
        $actions = WorkflowEngine::getActions();
        $actionClass = $actions[$node->type_config] ?? null;

        if ($actionClass) {
            return $actionClass::label();
        }

        return $node->type_config ?? __('filament-workflow::rules.unknown-action');
    }

    // ─── Step Interaction ────────────────────────────────────────────

    /**
     * Handle double-click on a rules step card.
     * Opens the node settings editor by default.
     * Override in subclass to customize behavior.
     */
    public function onRulesStepDoubleClick(int $nodeId): void
    {
        $this->mountAction('editNode', ['nodeId' => "wf-node-{$nodeId}"]);
    }

    // ─── Step CRUD ──────────────────────────────────────────────────

    /**
     * Add a new step to the workflow from the rules view.
     */
    public function addRulesStep(string $nodeType, ?string $typeConfig = null, ?string $label = null, ?int $afterNodeId = null, ?string $branch = null): void
    {
        if (! $this->canEditWorkflow()) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        if (! $this->selectedWorkflowId) {
            return;
        }

        // A supplied insertion anchor must belong to the selected workflow.
        if ($afterNodeId && ! WorkflowNode::whereKey($afterNodeId)->where('workflow_id', $this->selectedWorkflowId)->exists()) {
            return;
        }

        $newNode = WorkflowNode::create([
            'workflow_id' => $this->selectedWorkflowId,
            'node_type' => $nodeType,
            'type_config' => $typeConfig,
            'label' => $label,
            'position_x' => 0,
            'position_y' => 0,
        ]);

        // Insert into the connection chain
        if ($afterNodeId) {
            // Find the existing outgoing connection from afterNodeId
            $existingConn = WorkflowConnection::where('source_node_id', $afterNodeId)
                ->where('workflow_id', $this->selectedWorkflowId);

            if ($branch) {
                $existingConn->where('label', $branch);
            }

            $existingConn = $existingConn->first();

            if ($existingConn) {
                // Re-link: afterNode → newNode → oldTarget
                $oldTargetId = $existingConn->target_node_id;
                $existingConn->update(['target_node_id' => $newNode->id]);

                WorkflowConnection::create([
                    'workflow_id' => $this->selectedWorkflowId,
                    'source_node_id' => $newNode->id,
                    'target_node_id' => $oldTargetId,
                    'label' => null,
                ]);
            } else {
                // No existing connection — just append
                WorkflowConnection::create([
                    'workflow_id' => $this->selectedWorkflowId,
                    'source_node_id' => $afterNodeId,
                    'target_node_id' => $newNode->id,
                    'label' => $branch,
                ]);
            }
        }

        Notification::make()->title(__('filament-workflow::rules.step-added'))->success()->send();
    }

    /**
     * Remove a step and re-link the connection chain.
     */
    public function removeRulesStep(int $nodeId): void
    {
        if (! $this->canEditWorkflow()) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        $node = WorkflowNode::where('id', $nodeId)
            ->where('workflow_id', $this->selectedWorkflowId)
            ->first();

        if (! $node) {
            return;
        }

        $incoming = WorkflowConnection::where('target_node_id', $nodeId)
            ->where('workflow_id', $this->selectedWorkflowId)
            ->get();

        $outgoing = WorkflowConnection::where('source_node_id', $nodeId)
            ->where('workflow_id', $this->selectedWorkflowId)
            ->get();

        // Re-link: if one incoming and one outgoing, bridge them
        if ($incoming->count() === 1 && $outgoing->count() === 1) {
            $incoming->first()->update([
                'target_node_id' => $outgoing->first()->target_node_id,
            ]);
            $outgoing->first()->delete();
        } else {
            // Multiple connections — just delete them all
            WorkflowConnection::where('source_node_id', $nodeId)
                ->where('workflow_id', $this->selectedWorkflowId)
                ->delete();
            WorkflowConnection::where('target_node_id', $nodeId)
                ->where('workflow_id', $this->selectedWorkflowId)
                ->delete();
        }

        $node->delete();

        Notification::make()->title(__('filament-workflow::rules.step-removed'))->success()->send();
    }

    /**
     * Filament Action for deleting a single rules step with confirmation.
     */
    public function deleteStepAction(): Action
    {
        return Action::make('deleteStep')
            ->label(__('filament-workflow::rules.delete-step'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('filament-workflow::rules.delete-step-confirm'))
            ->action(function (array $arguments): void {
                $this->removeRulesStep($arguments['nodeId']);
            });
    }

    /**
     * Filament Action for deleting all unconnected steps with confirmation.
     */
    public function deleteAllOrphansAction(): Action
    {
        return Action::make('deleteAllOrphans')
            ->label(__('filament-workflow::rules.delete-all-orphans'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('filament-workflow::rules.delete-all-orphans-confirm'))
            ->action(function (): void {
                $rulesData = $this->getRulesTree();
                $orphans = $rulesData['orphans'] ?? [];

                if (empty($orphans)) {
                    return;
                }

                $orphanIds = collect($orphans)->pluck('id')->all();

                WorkflowNode::whereIn('id', $orphanIds)
                    ->where('workflow_id', $this->selectedWorkflowId)
                    ->delete();

                $count = count($orphanIds);
                Notification::make()
                    ->title(__('filament-workflow::rules.orphans-deleted', ['count' => $count]))
                    ->success()
                    ->send();
            });
    }

    // ─── Add Step Actions ───────────────────────────────────────────

    /**
     * Top-level "Add Step" — for adding a new root trigger or standalone step.
     */
    public function addStepAction(): Action
    {
        return Action::make('addStep')
            ->label(__('filament-workflow::rules.add-step'))
            ->icon('heroicon-o-plus')
            ->color('gray')
            ->schema([
                Select::make('node_type')
                    ->label(__('filament-workflow::rules.step-type'))
                    ->options([
                        'trigger' => NodeTypeEnum::TRIGGER->label(),
                        'condition' => NodeTypeEnum::CONDITION->label(),
                        'delay' => NodeTypeEnum::DELAY->label(),
                        'action' => NodeTypeEnum::ACTION->label(),
                    ])
                    ->required()
                    ->live(),

                Select::make('type_config')
                    ->label(__('filament-workflow::rules.sub-type'))
                    ->options(function (callable $get): array {
                        $nodeType = $get('node_type');

                        if ($nodeType === 'trigger') {
                            return collect(WorkflowEngine::getTriggers())
                                ->mapWithKeys(fn (string $class, string $key) => [$key => $class::label()])
                                ->all();
                        }

                        if ($nodeType === 'action') {
                            return collect(WorkflowEngine::getActions())
                                ->mapWithKeys(fn (string $class, string $key) => [$key => $class::label()])
                                ->all();
                        }

                        return [];
                    })
                    ->visible(fn (callable $get): bool => in_array($get('node_type'), ['trigger', 'action']))
                    ->required(fn (callable $get): bool => in_array($get('node_type'), ['trigger', 'action'])),

                TextInput::make('label')
                    ->label(__('filament-workflow::rules.step-label'))
                    ->placeholder(__('filament-workflow::rules.step-label-placeholder')),
            ])
            ->action(function (array $data): void {
                $this->addRulesStep(
                    nodeType: $data['node_type'],
                    typeConfig: $data['type_config'] ?? null,
                    label: $data['label'] ?? null,
                );
            });
    }

    /**
     * Inline "+" button — inserts a step after a specific node in the chain.
     */
    public function addStepAfterAction(): Action
    {
        return Action::make('addStepAfter')
            ->label(__('filament-workflow::rules.add-step'))
            ->icon('heroicon-o-plus')
            ->color('gray')
            ->schema([
                Select::make('node_type')
                    ->label(__('filament-workflow::rules.step-type'))
                    ->options([
                        'condition' => NodeTypeEnum::CONDITION->label(),
                        'delay' => NodeTypeEnum::DELAY->label(),
                        'action' => NodeTypeEnum::ACTION->label(),
                    ])
                    ->required()
                    ->live(),

                Select::make('type_config')
                    ->label(__('filament-workflow::rules.sub-type'))
                    ->options(function (callable $get): array {
                        $nodeType = $get('node_type');

                        if ($nodeType === 'action') {
                            return collect(WorkflowEngine::getActions())
                                ->mapWithKeys(fn (string $class, string $key) => [$key => $class::label()])
                                ->all();
                        }

                        return [];
                    })
                    ->visible(fn (callable $get): bool => $get('node_type') === 'action')
                    ->required(fn (callable $get): bool => $get('node_type') === 'action'),

                TextInput::make('label')
                    ->label(__('filament-workflow::rules.step-label'))
                    ->placeholder(__('filament-workflow::rules.step-label-placeholder')),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->addRulesStep(
                    nodeType: $data['node_type'],
                    typeConfig: $data['type_config'] ?? null,
                    label: $data['label'] ?? null,
                    afterNodeId: $arguments['afterNodeId'] ?? null,
                );
            });
    }

    // ─── Auto Layout ────────────────────────────────────────────────

    /**
     * Compute positions for nodes that were created in rules view (position 0,0).
     * Uses a simple layered tree layout.
     */
    public function computeAutoLayout(): void
    {
        if (! $this->canEditWorkflow()) {
            return;
        }

        if (! $this->selectedWorkflowId) {
            return;
        }

        $workflow = $this->findScopedWorkflow($this->selectedWorkflowId)?->load(['nodes', 'connections']);
        if (! $workflow) {
            return;
        }

        // Only layout nodes with no real position
        $unpositioned = $workflow->nodes->filter(
            fn (WorkflowNode $n) => $n->position_x == 0 && $n->position_y == 0
        );

        if ($unpositioned->isEmpty()) {
            return;
        }

        $nodes = $workflow->nodes->keyBy('id');
        $connections = $workflow->connections;

        // Build adjacency
        $adjacency = [];
        foreach ($connections as $conn) {
            $adjacency[$conn->source_node_id][] = [
                'target_id' => $conn->target_node_id,
                'label' => $conn->label,
            ];
        }

        // Find roots
        $hasIncoming = $connections->pluck('target_node_id')->unique()->all();
        $roots = $nodes->filter(fn (WorkflowNode $n) => ! in_array($n->id, $hasIncoming));

        if ($roots->isEmpty()) {
            $roots = $nodes->filter(fn (WorkflowNode $n) => $n->node_type === NodeTypeEnum::TRIGGER);
        }

        // BFS to assign layers
        $layers = [];
        $visited = [];
        $queue = [];

        foreach ($roots as $root) {
            $queue[] = ['id' => $root->id, 'depth' => 0, 'x_offset' => 0];
        }

        $xSpacing = 280;
        $ySpacing = 180;
        $startX = 400;
        $startY = 80;

        while (! empty($queue)) {
            $item = array_shift($queue);
            $nodeId = $item['id'];
            $depth = $item['depth'];

            if (in_array($nodeId, $visited)) {
                continue;
            }
            $visited[] = $nodeId;

            $layers[$depth][] = [
                'id' => $nodeId,
                'x_offset' => $item['x_offset'],
            ];

            $outgoing = $adjacency[$nodeId] ?? [];
            $childCount = count($outgoing);
            $node = $nodes[$nodeId] ?? null;

            foreach ($outgoing as $i => $edge) {
                $xOffset = 0;
                if ($node && $node->node_type === NodeTypeEnum::CONDITION) {
                    $xOffset = strtolower($edge['label'] ?? '') === 'yes' ? -($xSpacing / 2) : ($xSpacing / 2);
                } elseif ($childCount > 1) {
                    $xOffset = ($i - ($childCount - 1) / 2) * $xSpacing;
                }

                $queue[] = [
                    'id' => $edge['target_id'],
                    'depth' => $depth + 1,
                    'x_offset' => $item['x_offset'] + $xOffset,
                ];
            }
        }

        // Assign positions
        foreach ($layers as $depth => $layerNodes) {
            foreach ($layerNodes as $i => $item) {
                $node = $nodes[$item['id']] ?? null;
                if (! $node) {
                    continue;
                }

                // Only update unpositioned nodes
                if ($node->position_x != 0 || $node->position_y != 0) {
                    continue;
                }

                $x = $startX + $item['x_offset'];
                $y = $startY + ($depth * $ySpacing);

                WorkflowNode::where('id', $node->id)->update([
                    'position_x' => $x,
                    'position_y' => $y,
                ]);
            }
        }
    }
}
