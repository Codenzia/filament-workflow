<?php

declare(strict_types=1);

/**
 * WorkflowDesigner - Filament page with diagrammer canvas for visual workflow editing.
 *
 * Can be embedded in a project view or used standalone.
 * Manages workflow CRUD and syncs diagram state to the database.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Pages;

use Codenzia\FilamentDiagrammer\Components\DiagramCanvas;
use Codenzia\FilamentDiagrammer\Components\DiagramConnection;
use Codenzia\FilamentDiagrammer\Components\DiagramNode;
use Codenzia\FilamentDiagrammer\Concerns\HasDiagram;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Enums\NodeTypeEnum;
use Codenzia\FilamentWorkflow\Enums\WorkflowStatusEnum;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowConnection as WorkflowConnectionModel;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Codenzia\FilamentWorkflow\NodeTypes\ActionNodeType;
use Codenzia\FilamentWorkflow\NodeTypes\ConditionNodeType;
use Codenzia\FilamentWorkflow\NodeTypes\DelayNodeType;
use Codenzia\FilamentWorkflow\NodeTypes\TriggerNodeType;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

class WorkflowDesigner extends Page
{
    use HasDiagram;

    protected static string $view = 'filament-workflow::pages.workflow-designer';

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $title = 'Workflow Automation';

    protected static bool $shouldRegisterNavigation = false;

    public ?int $projectId = null;

    public ?string $modelType = null;

    public ?int $selectedWorkflowId = null;

    public array $workflows = [];

    public function mount(?int $projectId = null, ?string $modelType = null): void
    {
        $this->projectId = $projectId;
        $this->modelType = $modelType;
        $this->loadWorkflows();

        // Select the first workflow if available
        if (! empty($this->workflows)) {
            $this->selectedWorkflowId = $this->workflows[0]['id'];
        }

        $this->initializeDiagram();
    }

    public function loadWorkflows(): void
    {
        $query = Workflow::query()->orderBy('priority', 'desc')->orderBy('name');

        if ($this->projectId) {
            $query->where(function ($q): void {
                $q->where('project_id', $this->projectId)
                    ->orWhereNull('project_id');
            });
        }

        if ($this->modelType) {
            $query->where('model_type', $this->modelType);
        }

        $this->workflows = $query->get()->map(fn (Workflow $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'status' => $w->status->value,
            'statusLabel' => $w->status->label(),
            'statusColor' => $w->status->color(),
            'runCount' => $w->run_count,
        ])->toArray();
    }

    public function selectWorkflow(int $workflowId): void
    {
        $this->selectedWorkflowId = $workflowId;
        $this->initializeDiagram();
        $this->refreshDiagram();
    }

    protected function getDiagramCanvas(): DiagramCanvas
    {
        $canvas = DiagramCanvas::make()
            ->height('calc(100vh - 200px)')
            ->palette([
                TriggerNodeType::class,
                ConditionNodeType::class,
                DelayNodeType::class,
                ActionNodeType::class,
            ])
            ->dragToConnect()
            ->contextMenu()
            ->undoRedo()
            ->fitView()
            ->onNodeCreate(function (string $nodeId, string $nodeTypeClass, float $x, float $y): void {
                $this->persistNodeCreate($nodeId, $nodeTypeClass, $x, $y);
            })
            ->onNodeDelete(function (string $nodeId): void {
                $this->persistNodeDelete($nodeId);
            })
            ->onNodeMove(function (string $nodeId, float $x, float $y): void {
                $this->persistNodeMove($nodeId, $x, $y);
            })
            ->onConnectionCreate(function (string $sourceId, string $targetId): void {
                $this->persistConnectionCreate($sourceId, $targetId);
            })
            ->onConnectionDelete(function (string $sourceId, string $targetId): void {
                $this->persistConnectionDelete($sourceId, $targetId);
            })
            ->onSave(function (array $nodes, array $connections): void {
                $this->persistFullSave($nodes, $connections);
            });

        // Load nodes and connections from the selected workflow
        if ($this->selectedWorkflowId) {
            $workflow = Workflow::with(['nodes', 'connections'])->find($this->selectedWorkflowId);

            if ($workflow) {
                foreach ($workflow->nodes as $node) {
                    $diagramNode = DiagramNode::make("wf-node-{$node->id}")
                        ->type($this->resolveNodeTypeClass($node->node_type->value))
                        ->label($node->label ?? $node->node_type->label())
                        ->position($node->position_x, $node->position_y)
                        ->data(array_merge($node->config ?? [], [
                            'db_id' => $node->id,
                            'type_config' => $node->type_config,
                        ]));

                    $canvas->addNode($diagramNode);
                }

                foreach ($workflow->connections as $conn) {
                    $diagramConn = DiagramConnection::make(
                        "wf-node-{$conn->source_node_id}",
                        "wf-node-{$conn->target_node_id}",
                    )->label($conn->label);

                    $canvas->addConnection($diagramConn);
                }
            }
        }

        return $canvas;
    }

    protected function resolveNodeTypeClass(string $nodeType): string
    {
        return match ($nodeType) {
            'trigger' => TriggerNodeType::class,
            'condition' => ConditionNodeType::class,
            'delay' => DelayNodeType::class,
            'action' => ActionNodeType::class,
            default => TriggerNodeType::class,
        };
    }

    // ─── Persistence Callbacks ──────────────────────────────────────

    protected function persistNodeCreate(string $nodeId, string $nodeTypeClass, float $x, float $y): void
    {
        if (! $this->selectedWorkflowId) {
            return;
        }

        $nodeTypeMap = [
            TriggerNodeType::class => 'trigger',
            ConditionNodeType::class => 'condition',
            DelayNodeType::class => 'delay',
            ActionNodeType::class => 'action',
        ];

        WorkflowNode::create([
            'workflow_id' => $this->selectedWorkflowId,
            'node_type' => $nodeTypeMap[$nodeTypeClass] ?? 'action',
            'position_x' => $x,
            'position_y' => $y,
        ]);
    }

    protected function persistNodeDelete(string $nodeId): void
    {
        $dbId = $this->extractDbId($nodeId);
        if ($dbId) {
            WorkflowNode::where('id', $dbId)->delete();
        }
    }

    protected function persistNodeMove(string $nodeId, float $x, float $y): void
    {
        $dbId = $this->extractDbId($nodeId);
        if ($dbId) {
            WorkflowNode::where('id', $dbId)->update(['position_x' => $x, 'position_y' => $y]);
        }
    }

    protected function persistConnectionCreate(string $sourceId, string $targetId): void
    {
        if (! $this->selectedWorkflowId) {
            return;
        }

        $sourceDbId = $this->extractDbId($sourceId);
        $targetDbId = $this->extractDbId($targetId);

        if ($sourceDbId && $targetDbId) {
            WorkflowConnectionModel::create([
                'workflow_id' => $this->selectedWorkflowId,
                'source_node_id' => $sourceDbId,
                'target_node_id' => $targetDbId,
            ]);
        }
    }

    protected function persistConnectionDelete(string $sourceId, string $targetId): void
    {
        $sourceDbId = $this->extractDbId($sourceId);
        $targetDbId = $this->extractDbId($targetId);

        if ($sourceDbId && $targetDbId) {
            WorkflowConnectionModel::where('source_node_id', $sourceDbId)
                ->where('target_node_id', $targetDbId)
                ->delete();
        }
    }

    protected function persistFullSave(array $nodes, array $connections): void
    {
        // Canvas state is persisted incrementally via individual callbacks.
        // This is called on explicit save — update canvas_data for zoom/pan state.
        if ($this->selectedWorkflowId) {
            Workflow::where('id', $this->selectedWorkflowId)->update([
                'canvas_data' => ['nodes' => $nodes, 'connections' => $connections],
            ]);
        }
    }

    /**
     * Extract the database ID from a node ID like "wf-node-42".
     */
    protected function extractDbId(string $nodeId): ?int
    {
        // Look up the node data to find db_id
        $nodeArray = collect($this->diagramNodes)->firstWhere('id', $nodeId);
        $dbId = $nodeArray['data']['db_id'] ?? null;

        if (! $dbId && preg_match('/wf-node-(\d+)/', $nodeId, $matches)) {
            return (int) $matches[1];
        }

        return $dbId ? (int) $dbId : null;
    }

    // ─── Node Edit Form ─────────────────────────────────────────────

    protected function handleNodeSave(array $data, ?string $nodeId = null): void
    {
        $dbId = $nodeId ? $this->extractDbId($nodeId) : null;
        if (! $dbId) {
            return;
        }

        $updateData = ['config' => $data];

        if (isset($data['type_config'])) {
            $updateData['type_config'] = $data['type_config'];
        }

        if (isset($data['label'])) {
            $updateData['label'] = $data['label'];
        }

        WorkflowNode::where('id', $dbId)->update($updateData);
    }

    // ─── Workflow CRUD Actions ──────────────────────────────────────

    public function createWorkflowAction(): Action
    {
        return Action::make('createWorkflow')
            ->label('New Workflow')
            ->icon('heroicon-o-plus')
            ->form([
                TextInput::make('name')
                    ->label('Workflow Name')
                    ->required(),
                Textarea::make('description')
                    ->label('Description')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $workflow = Workflow::create([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'model_type' => $this->modelType ?? 'App\\Models\\Task',
                    'project_id' => $this->projectId,
                    'status' => 'draft',
                    'created_by' => auth()->id(),
                ]);

                $this->loadWorkflows();
                $this->selectWorkflow($workflow->id);

                Notification::make()->title('Workflow created')->success()->send();
            });
    }

    public function toggleWorkflowStatus(int $workflowId): void
    {
        $workflow = Workflow::find($workflowId);
        if (! $workflow) {
            return;
        }

        $newStatus = $workflow->status === WorkflowStatusEnum::ACTIVE
            ? WorkflowStatusEnum::INACTIVE
            : WorkflowStatusEnum::ACTIVE;

        $workflow->update(['status' => $newStatus->value]);
        $this->loadWorkflows();

        Notification::make()
            ->title("Workflow {$newStatus->label()}")
            ->success()
            ->send();
    }

    public function deleteWorkflow(int $workflowId): void
    {
        Workflow::where('id', $workflowId)->delete();

        $this->loadWorkflows();

        if ($this->selectedWorkflowId === $workflowId) {
            $this->selectedWorkflowId = $this->workflows[0]['id'] ?? null;
            $this->initializeDiagram();
            $this->refreshDiagram();
        }

        Notification::make()->title('Workflow deleted')->success()->send();
    }

    // ─── Time Triggers & Monitoring ─────────────────────────────────

    /**
     * Manually run time-based trigger evaluation for the selected workflow.
     */
    public function runTimeTriggers(): void
    {
        if (! $this->selectedWorkflowId) {
            return;
        }

        $workflow = Workflow::with('nodes')->find($this->selectedWorkflowId);
        if (! $workflow) {
            return;
        }

        $hasTimeTriggers = $workflow->nodes
            ->where('node_type', NodeTypeEnum::TRIGGER)
            ->filter(fn (WorkflowNode $n) => str_starts_with($n->type_config ?? '', 'time.'))
            ->isNotEmpty();

        if (! $hasTimeTriggers) {
            // Run as a generic manual evaluation for all trigger types
            $modelClass = $workflow->model_type;
            if (! class_exists($modelClass)) {
                Notification::make()->title('Invalid model type')->danger()->send();

                return;
            }

            Notification::make()
                ->title('This workflow has no time-based triggers')
                ->body('Manual run is only available for workflows with time-based triggers.')
                ->warning()
                ->send();

            return;
        }

        Artisan::call('workflow:process-time-triggers');
        $output = Artisan::output();

        $this->loadWorkflows();

        Notification::make()
            ->title('Time triggers processed')
            ->body(trim($output))
            ->success()
            ->send();
    }

    /**
     * Get execution history for the selected workflow.
     *
     * @return array<int, array{id: int, node_label: ?string, node_type: ?string, model_id: int, trigger_type: string, result: string, executed_at: string, details: ?array}>
     */
    public function getExecutionHistory(): array
    {
        if (! $this->selectedWorkflowId) {
            return [];
        }

        return WorkflowExecutionLog::where('workflow_id', $this->selectedWorkflowId)
            ->with('node')
            ->orderByDesc('executed_at')
            ->limit(50)
            ->get()
            ->map(fn (WorkflowExecutionLog $log) => [
                'id' => $log->id,
                'node_label' => $log->node?->label ?? $log->node?->node_type?->label(),
                'node_type' => $log->node?->node_type?->value,
                'model_id' => $log->model_id,
                'trigger_type' => $log->trigger_type,
                'result' => $log->result,
                'executed_at' => $log->executed_at?->diffForHumans(),
                'executed_at_full' => $log->executed_at?->toDateTimeString(),
                'details' => $log->details,
            ])
            ->toArray();
    }

    /**
     * Get time trigger preview — which models would match right now.
     *
     * @return array{has_time_triggers: bool, matching_count: int, models: array}
     */
    public function getTimeTriggerPreview(): array
    {
        if (! $this->selectedWorkflowId) {
            return ['has_time_triggers' => false, 'matching_count' => 0, 'models' => []];
        }

        $workflow = Workflow::with('nodes')->find($this->selectedWorkflowId);
        if (! $workflow) {
            return ['has_time_triggers' => false, 'matching_count' => 0, 'models' => []];
        }

        $timeTriggerNodes = $workflow->nodes
            ->where('node_type', NodeTypeEnum::TRIGGER)
            ->filter(fn (WorkflowNode $n) => str_starts_with($n->type_config ?? '', 'time.'));

        if ($timeTriggerNodes->isEmpty()) {
            return ['has_time_triggers' => false, 'matching_count' => 0, 'models' => []];
        }

        $modelClass = $workflow->model_type;
        if (! class_exists($modelClass)) {
            return ['has_time_triggers' => true, 'matching_count' => 0, 'models' => []];
        }

        $triggers = WorkflowEngine::getTriggers();
        $matchingModels = collect();

        foreach ($timeTriggerNodes as $node) {
            $triggerClass = $triggers[$node->type_config] ?? null;
            if (! $triggerClass || ! method_exists($triggerClass, 'scopeMatchingModels')) {
                continue;
            }

            $query = $modelClass::query();
            $models = $triggerClass::scopeMatchingModels($query, $node->config ?? [])
                ->limit(10)
                ->get();

            $matchingModels = $matchingModels->merge($models);
        }

        $unique = $matchingModels->unique('id');

        return [
            'has_time_triggers' => true,
            'matching_count' => $unique->count(),
            'models' => $unique->take(10)->map(fn ($m) => [
                'id' => $m->getKey(),
                'label' => $m->title ?? $m->name ?? "#{$m->getKey()}",
            ])->values()->toArray(),
        ];
    }

    /**
     * Get scheduler status information.
     *
     * @return array{last_run_at: ?string, total_runs: int, last_24h_executions: int, dedup_window: int}
     */
    public function getSchedulerStatus(): array
    {
        if (! $this->selectedWorkflowId) {
            return ['last_run_at' => null, 'total_runs' => 0, 'last_24h_executions' => 0, 'dedup_window' => 24];
        }

        $workflow = Workflow::find($this->selectedWorkflowId);
        if (! $workflow) {
            return ['last_run_at' => null, 'total_runs' => 0, 'last_24h_executions' => 0, 'dedup_window' => 24];
        }

        $dedupHours = config('filament-workflow.dedup_window_hours', 24);

        $last24h = WorkflowExecutionLog::where('workflow_id', $this->selectedWorkflowId)
            ->where('executed_at', '>=', now()->subHours(24))
            ->count();

        return [
            'last_run_at' => $workflow->last_run_at?->diffForHumans(),
            'last_run_at_full' => $workflow->last_run_at?->toDateTimeString(),
            'total_runs' => $workflow->run_count,
            'last_24h_executions' => $last24h,
            'dedup_window' => $dedupHours,
        ];
    }
}
