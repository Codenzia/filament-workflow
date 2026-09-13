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
use Codenzia\FilamentWorkflow\Concerns\HasRulesView;
use Codenzia\FilamentWorkflow\Engine\Contracts\TimeTriggerInterface;
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
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class WorkflowDesigner extends Page
{
    use HasDiagram;
    use HasRulesView;

    protected string $view = 'filament-workflow::pages.workflow-designer';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $title = 'Workflow Automation';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public ?int $projectId = null;

    #[Locked]
    public ?string $modelType = null;

    #[Locked]
    public ?int $selectedWorkflowId = null;

    public array $workflows = [];

    public bool $monitorOpen = false;

    public static function canAccess(): bool
    {
        $ability = config('filament-workflow.abilities.view', 'view_workflow');

        return filament()->auth()->user()?->can($ability) ?? false;
    }

    /**
     * Per-project authorization hook. Returns true by default (abilities are
     * global). Override in a subclass to enforce project-membership checks
     * before the designer mounts for a given project.
     */
    protected function authorizeProject(?int $projectId): bool
    {
        return true;
    }

    public function mount(?int $projectId = null, ?string $modelType = null): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($modelType && class_exists($modelType), 400, 'WorkflowDesigner requires a valid modelType.');
        abort_unless($this->authorizeProject($projectId), 403);

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

    /**
     * Resolve a workflow by id, scoped to the mounted model type and project.
     * Prevents cross-project / cross-model IDOR: a client cannot post an
     * out-of-scope workflow id and have it loaded.
     */
    protected function findScopedWorkflow(?int $id): ?Workflow
    {
        if (! $id) {
            return null;
        }

        return Workflow::query()
            ->when($this->modelType, fn ($q) => $q->where('model_type', $this->modelType))
            ->when($this->projectId, fn ($q) => $q->where(fn ($w) => $w->whereNull('project_id')->orWhere('project_id', $this->projectId)))
            ->find($id);
    }

    public function selectWorkflow(int $workflowId): void
    {
        // Only select ids that are in scope for the mounted context.
        if (! $this->findScopedWorkflow($workflowId)) {
            return;
        }

        $this->selectedWorkflowId = $workflowId;
        $this->initializeDiagram();
        $this->refreshDiagram();
    }

    /**
     * Diagram mutations follow the workflow's own edit authorization; the
     * diagrammer fails closed, so a workflow the user may not edit renders
     * read-only rather than silently accepting canvas changes.
     */
    protected function canEditDiagram(): bool
    {
        return $this->canEditWorkflow() && $this->selectedWorkflowId !== null;
    }

    protected function getDiagramCanvas(): DiagramCanvas
    {
        $canvas = DiagramCanvas::make()
            ->height('600px')
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
            ->onNodeCreate(function (string $nodeId, ?string $nodeTypeClass, float $x, float $y): void {
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
            $workflow = $this->findScopedWorkflow($this->selectedWorkflowId)?->load(['nodes', 'connections']);

            if ($workflow) {
                foreach ($workflow->nodes as $node) {
                    $nodeTypeClass = $this->resolveNodeTypeClass($node->node_type->value);
                    $diagramNode = DiagramNode::make("wf-node-{$node->id}")
                        ->type($nodeTypeClass)
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
        return NodeTypeEnum::tryFrom($nodeType)?->nodeTypeClass() ?? TriggerNodeType::class;
    }

    // ─── Persistence Callbacks ──────────────────────────────────────

    protected function persistNodeCreate(string $nodeId, ?string $nodeTypeClass, float $x, float $y): void
    {
        if (! $this->canEditWorkflow() || ! $this->selectedWorkflowId) {
            return;
        }

        $nodeType = collect(NodeTypeEnum::cases())
            ->first(fn (NodeTypeEnum $case): bool => $case->nodeTypeClass() === $nodeTypeClass)?->value
            ?? NodeTypeEnum::ACTION->value;

        WorkflowNode::create([
            'workflow_id' => $this->selectedWorkflowId,
            'node_type' => $nodeType,
            'position_x' => $x,
            'position_y' => $y,
        ]);
    }

    protected function persistNodeDelete(string $nodeId): void
    {
        if (! $this->canEditWorkflow() || ! $this->selectedWorkflowId) {
            return;
        }

        $dbId = $this->extractDbId($nodeId);
        if ($dbId) {
            WorkflowNode::where('id', $dbId)
                ->where('workflow_id', $this->selectedWorkflowId)
                ->delete();
        }
    }

    protected function persistNodeMove(string $nodeId, float $x, float $y): void
    {
        if (! $this->canEditWorkflow() || ! $this->selectedWorkflowId) {
            return;
        }

        $dbId = $this->extractDbId($nodeId);
        if ($dbId) {
            WorkflowNode::where('id', $dbId)
                ->where('workflow_id', $this->selectedWorkflowId)
                ->update(['position_x' => $x, 'position_y' => $y]);
        }
    }

    protected function persistConnectionCreate(string $sourceId, string $targetId): void
    {
        if (! $this->canEditWorkflow() || ! $this->selectedWorkflowId) {
            return;
        }

        $sourceDbId = $this->extractDbId($sourceId);
        $targetDbId = $this->extractDbId($targetId);

        if ($sourceDbId && $targetDbId) {
            // Both nodes must belong to the selected workflow, else a client
            // could graft foreign-workflow nodes into this graph.
            $inScope = WorkflowNode::whereIn('id', [$sourceDbId, $targetDbId])
                ->where('workflow_id', $this->selectedWorkflowId)
                ->count() === 2;

            if (! $inScope) {
                return;
            }

            WorkflowConnectionModel::create([
                'workflow_id' => $this->selectedWorkflowId,
                'source_node_id' => $sourceDbId,
                'target_node_id' => $targetDbId,
            ]);
        }
    }

    protected function persistConnectionDelete(string $sourceId, string $targetId): void
    {
        if (! $this->canEditWorkflow() || ! $this->selectedWorkflowId) {
            return;
        }

        $sourceDbId = $this->extractDbId($sourceId);
        $targetDbId = $this->extractDbId($targetId);

        if ($sourceDbId && $targetDbId) {
            WorkflowConnectionModel::where('workflow_id', $this->selectedWorkflowId)
                ->where('source_node_id', $sourceDbId)
                ->where('target_node_id', $targetDbId)
                ->delete();
        }
    }

    protected function persistFullSave(array $nodes, array $connections): void
    {
        // Canvas state is persisted incrementally via individual callbacks.
        // This is called on explicit save — update canvas_data for zoom/pan state.
        if ($this->canEditWorkflow() && $this->selectedWorkflowId) {
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
        if (! $this->canEditWorkflow() || ! $this->selectedWorkflowId) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

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

        WorkflowNode::where('id', $dbId)
            ->where('workflow_id', $this->selectedWorkflowId)
            ->update($updateData);
    }

    // ─── Workflow Templates (override in subclass to provide) ───────

    /**
     * Return available workflow templates.
     * Override in subclass to provide app-specific predefined workflows.
     *
     * Each template is an array with:
     *   - name: string — display name (also used as default workflow name)
     *   - description: string — what this template does
     *   - icon: string — heroicon name
     *   - nodes: array — list of node definitions [{node_type, type_config, label, config, position_x, position_y}]
     *   - connections: array — list of connections [{source, target, label}] where source/target are node array indices
     *
     * @return array<string, array>
     */
    protected function getWorkflowTemplates(): array
    {
        return [];
    }

    /**
     * Scaffold nodes and connections from a template onto a workflow.
     */
    protected function scaffoldFromTemplate(Workflow $workflow, array $template): void
    {
        $nodeMap = []; // template index => DB node

        foreach ($template['nodes'] ?? [] as $index => $nodeDef) {
            $nodeMap[$index] = WorkflowNode::create([
                'workflow_id' => $workflow->id,
                'node_type' => $nodeDef['node_type'],
                'type_config' => $nodeDef['type_config'] ?? null,
                'label' => $nodeDef['label'] ?? null,
                'config' => $nodeDef['config'] ?? [],
                'position_x' => $nodeDef['position_x'] ?? ($index * 250 + 100),
                'position_y' => $nodeDef['position_y'] ?? 200,
            ]);
        }

        foreach ($template['connections'] ?? [] as $connDef) {
            $sourceNode = $nodeMap[$connDef['source']] ?? null;
            $targetNode = $nodeMap[$connDef['target']] ?? null;
            if (! $sourceNode || ! $targetNode) {
                continue;
            }

            WorkflowConnectionModel::create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $sourceNode->id,
                'target_node_id' => $targetNode->id,
                'label' => $connDef['label'] ?? null,
            ]);
        }
    }

    // ─── Workflow Form Schema (override in subclass to customize) ───

    /**
     * Schema fields for the create workflow dialog.
     * Override to add app-specific fields (e.g., model type selector, tags).
     *
     * @return array<Component>
     */
    protected function getCreateWorkflowSchema(): array
    {
        $schema = [];

        $templates = $this->getWorkflowTemplates();
        if (! empty($templates)) {
            $schema[] = Select::make('template')
                ->label('Start from template')
                ->placeholder('Blank workflow')
                ->options(
                    collect($templates)->mapWithKeys(fn (array $t, string $key) => [
                        $key => $t['name'],
                    ])->all()
                )
                ->live()
                ->afterStateUpdated(function ($state, callable $set) use ($templates): void {
                    if ($state && isset($templates[$state])) {
                        $set('name', $templates[$state]['name']);
                        $set('description', $templates[$state]['description'] ?? '');
                    }
                });
        }

        $schema[] = TextInput::make('name')
            ->label('Workflow Name')
            ->required();
        $schema[] = Textarea::make('description')
            ->label('Description')
            ->rows(2);

        return $schema;
    }

    /**
     * Schema fields for the edit workflow dialog.
     * Override to add app-specific fields.
     *
     * @return array<Component>
     */
    protected function getEditWorkflowSchema(): array
    {
        return [
            TextInput::make('name')
                ->label('Workflow Name')
                ->required(),
            Textarea::make('description')
                ->label('Description')
                ->rows(2),
            Select::make('status')
                ->label('Status')
                ->options(WorkflowStatusEnum::class)
                ->required(),
            TextInput::make('priority')
                ->label('Priority')
                ->helperText('Higher priority workflows execute first (0 = default)')
                ->numeric()
                ->default(0),
        ];
    }

    /**
     * Map form data to workflow attributes for create.
     * Override to handle custom fields.
     */
    protected function mapCreateData(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'model_type' => $this->modelType,
            'project_id' => $this->projectId,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ];
    }

    /**
     * Map form data to workflow attributes for update.
     * Override to handle custom fields.
     */
    protected function mapEditData(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'priority' => $data['priority'] ?? 0,
        ];
    }

    /**
     * Fill form data for edit workflow dialog.
     * Override to populate custom fields.
     */
    protected function fillEditForm(Workflow $workflow): array
    {
        return [
            'name' => $workflow->name,
            'description' => $workflow->description,
            'status' => $workflow->status->value,
            'priority' => $workflow->priority,
        ];
    }

    // ─── Workflow CRUD Actions ──────────────────────────────────────

    public function canCreateWorkflow(): bool
    {
        return filament()->auth()->user()?->can(config('filament-workflow.abilities.create', 'create_workflow')) ?? false;
    }

    public function canEditWorkflow(): bool
    {
        return filament()->auth()->user()?->can(config('filament-workflow.abilities.edit', 'edit_workflow')) ?? false;
    }

    public function canDeleteWorkflow(): bool
    {
        return filament()->auth()->user()?->can(config('filament-workflow.abilities.delete', 'delete_workflow')) ?? false;
    }

    public function canRunTimeTriggers(): bool
    {
        return filament()->auth()->user()?->can(config('filament-workflow.abilities.run_triggers', 'run_workflow_triggers')) ?? false;
    }

    public function createWorkflowAction(): Action
    {
        return Action::make('createWorkflow')
            ->label('New Workflow')
            ->icon('heroicon-o-plus')
            ->iconButton()
            ->tooltip('New Workflow')
            ->visible(fn (): bool => $this->canCreateWorkflow())
            ->schema($this->getCreateWorkflowSchema())
            ->action(function (array $data): void {
                $workflow = Workflow::create($this->mapCreateData($data));

                // Scaffold from template if selected
                $templateKey = $data['template'] ?? null;
                $templates = $this->getWorkflowTemplates();
                if ($templateKey && isset($templates[$templateKey])) {
                    $this->scaffoldFromTemplate($workflow, $templates[$templateKey]);
                }

                $this->loadWorkflows();
                $this->selectWorkflow($workflow->id);

                Notification::make()->title('Workflow created')->success()->send();
            });
    }

    public function editWorkflowAction(): Action
    {
        return Action::make('editWorkflow')
            ->label('Edit Workflow')
            ->icon('heroicon-o-cog-6-tooth')
            ->iconButton()
            ->tooltip('Workflow Settings')
            ->visible(fn (): bool => $this->canEditWorkflow())
            ->fillForm(function (array $arguments): array {
                $workflow = $this->findScopedWorkflow($arguments['id'] ?? null);

                return $workflow ? $this->fillEditForm($workflow) : [];
            })
            ->schema($this->getEditWorkflowSchema())
            ->action(function (array $data, array $arguments): void {
                if (! $this->canEditWorkflow()) {
                    Notification::make()->title('Unauthorized')->danger()->send();

                    return;
                }

                $workflow = $this->findScopedWorkflow($arguments['id'] ?? null);
                if (! $workflow) {
                    return;
                }

                $workflow->update($this->mapEditData($data));

                $this->loadWorkflows();

                Notification::make()->title('Workflow updated')->success()->send();
            });
    }

    public function toggleWorkflowStatus(int $workflowId): void
    {
        if (! $this->canEditWorkflow()) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        $workflow = $this->findScopedWorkflow($workflowId);
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

    public function deleteWorkflowAction(): Action
    {
        return Action::make('deleteWorkflow')
            ->label('Delete Workflow')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => $this->canDeleteWorkflow())
            ->requiresConfirmation()
            ->modalDescription('This will permanently delete the workflow and all its steps. This cannot be undone.')
            ->action(function (array $arguments): void {
                $this->deleteWorkflow($arguments['id']);
            });
    }

    public function deleteWorkflow(int $workflowId): void
    {
        if (! $this->canDeleteWorkflow()) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        $workflow = $this->findScopedWorkflow($workflowId);
        if (! $workflow) {
            return;
        }

        $workflow->delete();

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
        if (! $this->canRunTimeTriggers()) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        if (! $this->selectedWorkflowId) {
            return;
        }

        $workflow = $this->findScopedWorkflow($this->selectedWorkflowId)?->load('nodes');
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

        Artisan::call('workflow:process-time-triggers', ['--workflow' => $this->selectedWorkflowId]);
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
    #[Computed]
    public function getExecutionHistory(): array
    {
        if (! $this->selectedWorkflowId || ! $this->findScopedWorkflow($this->selectedWorkflowId)) {
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
    #[Computed]
    public function getTimeTriggerPreview(): array
    {
        if (! $this->selectedWorkflowId) {
            return ['has_time_triggers' => false, 'matching_count' => 0, 'models' => []];
        }

        $workflow = $this->findScopedWorkflow($this->selectedWorkflowId)?->load('nodes');
        if (! $workflow) {
            return ['has_time_triggers' => false, 'matching_count' => 0, 'models' => []];
        }

        $timeTriggerNodes = $workflow->nodes
            ->where('node_type', NodeTypeEnum::TRIGGER)
            ->filter(fn (WorkflowNode $n) => str_starts_with($n->type_config ?? '', 'time.'));

        if ($timeTriggerNodes->isEmpty()) {
            return ['has_time_triggers' => false, 'matching_count' => 0, 'models' => []];
        }

        // Defer the live host-model queries until the monitor panel is opened,
        // so routine interactions (node drag, tab switch) stay cheap.
        if (! $this->monitorOpen) {
            return ['has_time_triggers' => true, 'matching_count' => 0, 'models' => []];
        }

        $modelClass = $workflow->model_type;
        if (! class_exists($modelClass)) {
            return ['has_time_triggers' => true, 'matching_count' => 0, 'models' => []];
        }

        $triggers = WorkflowEngine::getTriggers();
        $matchingModels = collect();

        foreach ($timeTriggerNodes as $node) {
            $triggerClass = $triggers[$node->type_config] ?? null;
            if (! $triggerClass) {
                continue;
            }

            // Resolve an instance and call it — never call a (possibly
            // non-static) method statically on the class string.
            $trigger = app($triggerClass);
            if (! $trigger instanceof TimeTriggerInterface) {
                continue;
            }

            $query = $modelClass::query();
            $models = $trigger->matchingModels($query, $node->config ?? [])
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
    #[Computed]
    public function getSchedulerStatus(): array
    {
        if (! $this->selectedWorkflowId) {
            return ['last_run_at' => null, 'total_runs' => 0, 'last_24h_executions' => 0, 'dedup_window' => 24];
        }

        $workflow = $this->findScopedWorkflow($this->selectedWorkflowId);
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
