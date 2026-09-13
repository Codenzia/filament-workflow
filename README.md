# Filament Workflow — Visual automation engine for Filament

[![Latest Version](https://img.shields.io/packagist/v/codenzia/filament-workflow.svg?style=flat-square)](https://packagist.org/packages/codenzia/filament-workflow)
[![PHP Version](https://img.shields.io/packagist/php-v/codenzia/filament-workflow.svg?style=flat-square)](https://packagist.org/packages/codenzia/filament-workflow)
[![Filament](https://img.shields.io/badge/Filament-v4%20%7C%20v5-f59e0b?style=flat-square)](https://filamentphp.com)
[![Tests](https://img.shields.io/badge/tests-Pest%20v3-8b5cf6?style=flat-square)](https://pestphp.com)
[![License](https://img.shields.io/packagist/l/codenzia/filament-workflow.svg?style=flat-square)](LICENSE.md)

A **visual workflow automation engine for [Filament v4 and v5](https://filamentphp.com)** built on [`codenzia/filament-diagrammer`](https://github.com/Codenzia/filament-diagrammer). Drag-and-drop node builder, trigger → condition → delay → action pipelines, IF/ELSE branching, time-based triggers, extensible trigger + action registries with dynamic config forms — all native Filament, no external workflow service required.

> **Why this exists.** Zapier/Make.com are great until your data lives behind auth and your triggers fire from Eloquent events. Pulling app data into a third-party automation service means webhooks, OAuth, and ongoing per-execution fees. `filament-workflow` puts the entire engine inside your Laravel app — visual builder, execution, logging, retries — with first-class access to your models.

> **Try it live:** A working integration is included in the [Codenzia plugins demo](https://github.com/Codenzia/plugins-demo) at `/admin/demo/workflow`.

---

## Features

- **Visual flow builder** — drag-and-drop nodes powered by `filament-diagrammer`.
- **Trigger → Condition → Delay → Action pipeline.**
- **IF/ELSE branching** with condition nodes.
- **Time-based triggers** — due-date reminders, overdue escalation, etc.
- **Extensible trigger + action registry** with **dynamic config forms** rendered per registered type.
- **Workflow templates** — predefined flows you can ship as starting points.
- **Subclassable designer** — extend the builder page for app-specific customisation.
- **Execution logging + audit trail** — every step, every retry recorded.
- **Global + project-scoped workflows.**

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.3` |
| Filament | `^4.0 \|\| ^5.0` |
| `codenzia/filament-diagrammer` | Latest |

---

## Installation

```bash
composer require codenzia/filament-workflow
```

Publish and run migrations:

```bash
php artisan vendor:publish --tag=filament-workflow-migrations
php artisan migrate
```

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=filament-workflow-config
```

### Tailwind v4 Custom Theme

If your Filament panel uses a custom theme (Tailwind CSS v4), add source paths for both this package and its dependency `filament-diagrammer`:

```css
/* resources/css/filament/{panel}/theme.css */
@source '../../../../vendor/codenzia/*/src/**/*.php';
@source '../../../../vendor/codenzia/*/resources/views/**/*.blade.php';
```

This wildcard pattern covers all Codenzia packages (including the `filament-diagrammer` dependency) at once.

Then rebuild your assets (`npm run build`).

## Quick Start

### 1. Add `HasWorkflows` trait to your model

```php
use Codenzia\FilamentWorkflow\Concerns\HasWorkflows;

class Task extends Model
{
    use HasWorkflows;
}
```

This automatically dispatches workflow evaluation on model `created` and `updated` events.

### 2. Register triggers and actions

In your `AppServiceProvider::boot()`:

```php
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;

// Triggers
WorkflowEngine::registerTrigger('task.status_changed', TaskStatusChangedTrigger::class);
WorkflowEngine::registerTrigger('task.assigned', TaskAssignedTrigger::class);

// Actions
WorkflowEngine::registerAction('change_task_status', ChangeTaskStatusAction::class);
WorkflowEngine::registerAction('assign_user', AssignUserAction::class);
WorkflowEngine::registerAction('escalate', EscalateAction::class);

// Model fields. This is BOTH the designer dropdown list and the security
// allow-list: only fields registered for a model can be written by
// ChangeFieldAction, and only their changes are serialised into workflow
// event payloads. A model with no registration has neither — its updates
// dispatch no workflow evaluation at all.
WorkflowEngine::registerModelFields(Task::class, [
    'title' => 'Title',
    'status' => 'Status',
    'priority' => 'Priority',
    'assigned_to_user_id' => 'Assigned To',
    'due_date' => 'Due Date',
    'progress' => 'Progress (%)',
    // ...
]);
```

### 3. Embed the designer in a page

```blade
@livewire(
    \Codenzia\FilamentWorkflow\Pages\WorkflowDesigner::class,
    ['projectId' => $project->id, 'modelType' => Task::class]
)
```

The `modelType` parameter is **required** — the designer will abort if not provided.

### Diagram & Rules Tabs

The designer includes two tabs above the canvas:

- **Diagram** — Visual drag-and-drop canvas (default)
- **Rules** — Structured form-based step list

Both tabs edit the same underlying data. Changes made in one tab are immediately visible when switching to the other.

The **Rules tab** shows:
- Connected flows as a vertical step list with condition branching (Yes/No columns)
- Inline `+` buttons between steps to insert new nodes
- An "Add Step" button for creating new trigger/action/condition/delay steps
- An **Unconnected Steps** section (collapsed by default) for orphaned nodes

Double-click any step card to open its settings editor.

When switching from Rules back to Diagram, nodes created in the rules view are automatically positioned using a layered tree layout algorithm.

## Configuration

Published to `config/filament-workflow.php`:

```php
return [
    // Queue name for automation jobs
    'queue' => 'automations',

    // Max nodes per workflow run (infinite loop prevention)
    'max_nodes_per_run' => 50,

    // Delayed continuations one run may schedule, and its maximum lifetime
    'max_run_hops' => 100,
    'max_run_days' => 30,

    // Time trigger check interval (minutes)
    'time_trigger_interval' => 15,

    // Candidate models loaded per chunk by the time-trigger command
    'time_trigger_chunk_size' => 500,

    // Hours before same trigger can re-fire on same model
    'dedup_window_hours' => 24,

    // Days to keep execution logs
    'log_retention_days' => 90,

    // Backoff when a queued job cannot acquire the per-model lock
    'lock_retry_base_seconds' => 5,
    'lock_retry_max_seconds' => 300,
];
```

## Concepts

### Node Types

| Type | Color | Purpose | Connections |
|------|-------|---------|-------------|
| **Trigger** | Green | Entry point — what event starts the flow | No inputs, one output |
| **Condition** | Yellow | Filter with YES/NO branching | One input, two outputs (Yes/No) |
| **Delay** | Blue | Wait before continuing (minutes/hours/days) | One input, one output |
| **Action** | Purple | Execute an operation | One input, any outputs |

Each node type includes a `description()` method with localizable help text (via `filament-workflow::node-types.*`). The palette sidebar shows a `?` tooltip on hover with this description.

Double-click any node to open its settings editor. Right-click for the context menu with Settings, Delete, Connect to, and more.

### Triggers (Built-in)

- **ModelCreated** — fires when a model is created
- **ModelUpdated** — fires when a model is updated
- **FieldChanged** — fires when a specific field changes (optional `from`/`to` constraints)

### Actions (Built-in)

- **ChangeField** — updates a field on the model
- **SendNotification** — sends a Laravel notification to a user
- **DispatchEvent** — dispatches a Laravel event

### Conditions

**Operators**: `equals`, `not_equals`, `greater_than`, `less_than`, `greater_than_or_equal`, `less_than_or_equal`, `contains`, `not_contains`, `is_null`, `is_not_null`, `in`, `not_in`

**Logic**: `and` (all conditions must match) or `or` (any condition can match)

## Extending

### Custom Triggers

Implement `TriggerInterface`. The `configSchema()` method returns Filament form fields that appear in the node settings dialog when this trigger type is selected:

```php
use Codenzia\FilamentWorkflow\Engine\Contracts\TriggerInterface;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

class TaskStatusChangedTrigger implements TriggerInterface
{
    public static function label(): string
    {
        return 'Task Status Changed';
    }

    public function matches(Model $model, array $config, array $context): bool
    {
        $changedFields = $context['changed_fields'] ?? [];

        if (! in_array('status', $changedFields)) {
            return false;
        }

        if (isset($config['to'])) {
            $newValue = $context['new']['status'] ?? null;
            if ($newValue != $config['to']) {
                return false;
            }
        }

        return true;
    }

    public static function configSchema(): array
    {
        return [
            Select::make('config.from')
                ->label('From Status')
                ->placeholder('Any')
                ->options(TaskStatusEnum::class),
            Select::make('config.to')
                ->label('To Status')
                ->placeholder('Any')
                ->options(TaskStatusEnum::class),
        ];
    }
}
```

### Custom Actions

Implement `ActionHandlerInterface`. The `configSchema()` method defines what the user configures on the action node:

```php
use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;

class AssignUserAction implements ActionHandlerInterface
{
    public static function label(): string
    {
        return 'Assign User';
    }

    public function execute(Model $model, array $config, array $context): array
    {
        $userId = $config['user_id'] ?? null;
        if (! $userId) {
            return ['error' => 'No user specified'];
        }

        $model->update(['assigned_to_user_id' => $userId]);

        return ['action' => 'assign_user', 'to_user_id' => $userId];
    }

    public static function configSchema(): array
    {
        return [
            Select::make('config.user_id')
                ->label('Assign to User')
                ->options(fn () => User::pluck('name', 'id')->all())
                ->searchable(),
            Select::make('config.role')
                ->label('Or Assign by Project Role')
                ->options([
                    'owner' => 'Project Owner',
                    'lead' => 'Team Lead',
                ]),
        ];
    }
}
```

**Config field naming**: Use the `config.` prefix (e.g., `config.user_id`, `config.status`) so values are stored in the node's `config` JSON column. The node type forms automatically show/hide config fields based on the selected trigger/action type.

### Register in ServiceProvider

```php
WorkflowEngine::registerTrigger('task.status_changed', TaskStatusChangedTrigger::class);
WorkflowEngine::registerAction('assign_user', AssignUserAction::class);
```

## Workflow Templates

Provide predefined workflow templates by subclassing `WorkflowDesigner` and overriding `getWorkflowTemplates()`:

```php
use Codenzia\FilamentWorkflow\Pages\WorkflowDesigner;

class TaskWorkflowDesigner extends WorkflowDesigner
{
    protected function getWorkflowTemplates(): array
    {
        return [
            'auto_close_parent' => [
                'name' => 'Auto-Close Parent Task',
                'description' => 'When all subtasks are completed, close the parent.',
                'icon' => 'heroicon-o-check-circle',
                'nodes' => [
                    [
                        'node_type' => 'trigger',
                        'type_config' => 'subtasks.all_closed',
                        'label' => 'All Subtasks Closed',
                        'config' => [],
                        'position_x' => 300,
                        'position_y' => 100,
                    ],
                    [
                        'node_type' => 'action',
                        'type_config' => 'close_parent',
                        'label' => 'Close Parent',
                        'config' => [],
                        'position_x' => 300,
                        'position_y' => 350,
                    ],
                ],
                'connections' => [
                    ['source' => 0, 'target' => 1],
                ],
            ],
        ];
    }
}
```

When templates are available, the "New Workflow" dialog shows a **Start from template** dropdown. Selecting a template pre-fills the name and description, and scaffolds all nodes and connections on creation.

Templates reference node indices (0, 1, 2...) for connections — the `source` and `target` values map to the `nodes` array order.

## Customizing the Designer

The `WorkflowDesigner` is designed for subclassing. Override these methods to customize:

| Method | Purpose |
|--------|---------|
| `getWorkflowTemplates()` | Provide predefined workflow templates |
| `getCreateWorkflowSchema()` | Form fields for "New Workflow" dialog |
| `getEditWorkflowSchema()` | Form fields for "Settings" dialog |
| `mapCreateData(array $data)` | Transform create form data → DB attributes |
| `mapEditData(array $data)` | Transform edit form data → DB attributes |
| `fillEditForm(Workflow $workflow)` | Populate edit form from model |
| `getRulesTree()` | Customize the rules view tree structure |
| `getStepSummary(WorkflowNode $node)` | Customize step card descriptions |
| `onRulesStepDoubleClick(int $nodeId)` | Customize double-click behavior on rules steps |
| `addStepAction()` | Customize the "Add Step" modal form |
| `computeAutoLayout()` | Customize auto-positioning algorithm |

Example — embedding a subclass:

```blade
@livewire(
    \App\Filament\Pages\TaskWorkflowDesigner::class,
    ['projectId' => $project->id, 'modelType' => Task::class]
)
```

Remember to register the Livewire component in your `AppServiceProvider`:

```php
Livewire::component('app.filament.pages.task-workflow-designer', TaskWorkflowDesigner::class);
```

## Time-Based Triggers

For triggers based on due dates or overdue status, schedule the command:

```php
// routes/console.php
Schedule::command('workflow:process-time-triggers')
    ->cron('*/'.config('filament-workflow.time_trigger_interval').' * * * *');
```

The interval (in minutes) is driven by the `time_trigger_interval` config value.

The command finds active workflows with time-based trigger nodes, queries matching models, and dispatches evaluation jobs. Deduplication prevents the same model+workflow from re-triggering within the configured window (default: 24 hours).

### Time Trigger Query Scoping

For time-based triggers to work with the preview and scheduler, implement the optional `scopeMatchingModels` static method on your trigger class:

```php
class TaskOverdueTrigger implements TriggerInterface
{
    // ... matches() and configSchema() methods ...

    /**
     * Scope query to find models that match this time trigger.
     * Used by the scheduler command and the designer's preview panel.
     */
    public static function scopeMatchingModels(Builder $query, array $config): Builder
    {
        $daysOverdue = (int) ($config['days_overdue'] ?? 0);

        return $query
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->subDays($daysOverdue))
            ->where('status', '!=', 'closed');
    }
}
```

### Run Now

The WorkflowDesigner includes a **Run Now** button for workflows with time-based triggers. This manually invokes the `workflow:process-time-triggers` command, allowing users to test their time trigger configuration without waiting for the scheduler.

### Time Trigger Preview

The monitor panel shows a live preview of which models would match the current time trigger configuration. This helps users validate their trigger setup before activating the workflow.

## Monitoring & Execution History

The WorkflowDesigner includes a collapsible **Monitor** panel below the canvas that shows:

### Scheduler Status
- Total workflow runs
- Last run timestamp
- Number of node executions in the last 24 hours
- Configured deduplication window

### Time Trigger Preview
- Whether the workflow has time-based triggers
- How many models would match right now
- A list of the first 10 matching models (ID + label)

### Recent Executions
- Color-coded execution log (green = success, red = failure, amber = error, blue = delayed, gray = skipped)
- Node label, model ID, and relative timestamp for each execution
- Last 10 executions shown inline, up to 50 available via scroll

## Execution Logs

Every node execution is logged to `workflow_execution_logs` with:

- Workflow and node IDs
- Model type and ID
- Trigger type
- Result:
  - `success` — the node did what it says it does
  - `skipped` — a deliberate no-op (trigger didn't match, action guard held);
    the branch continues
  - `error` — the action reported it could not act; the branch stops but the
    run is not rolled back
  - `failure` — the node threw or is unregistered; the whole run rolls back
  - `delayed` — a continuation was scheduled
- Execution details, including the `run_id` and `hop` that identify the run
- Timestamp

## Infinite Loop Prevention

Every run carries a durable identity (`run_id`) that travels with each delayed
continuation, so the guards below apply to the whole run rather than to one
process:

1. **Static `$executing` flag** — prevents re-entrant execution when model changes trigger new evaluations
2. **Visited-node set** — a node already executed in the run is skipped, including when the cycle passes through a delay node
3. **Max nodes per run** — limits the number of nodes executed in a single workflow run (default: 50)
4. **Max hops / max duration** — a run may schedule at most `max_run_hops`
   delayed continuations (default 100) and may live at most `max_run_days`
   (default 30) before it ends with a recorded reason

## Database Schema

### `workflows`
Stores workflow definitions with name, model type, project scope, status, and priority.

> **`project_id` is host-owned.** Workflows can be scoped to a project or left
> global (`project_id = null`). This package does **not** ship a `projects`
> table, so `project_id` is a plain nullable, indexed column with no enforced
> foreign key — migrations stay portable across sqlite/mysql/pgsql on hosts that
> have no `projects` table. If your application provides a `projects` table, the
> migration attaches a `nullOnDelete` foreign key to it automatically.

### `workflow_nodes`
Individual nodes in a workflow: type (trigger/condition/delay/action), configuration, and canvas position.

### `workflow_connections`
Edges between nodes: source, target, optional label (e.g., "Yes"/"No"), and sort order.

### `workflow_execution_logs`
Audit trail of every node execution with result and details.

## Testing

```bash
vendor/bin/pest
```

## License

Proprietary — Codenzia
