# Filament Workflow

Visual workflow automation engine for Filament 4+ built on [filament-diagrammer](https://github.com/Codenzia/filament-diagrammer).

## Features

- Visual flow builder with drag-and-drop nodes
- Trigger → Condition → Delay → Action pipeline
- IF/ELSE branching with condition nodes
- Time-based triggers (due date reminders, overdue escalation)
- Extensible trigger and action registry
- Execution logging and audit trail
- Global + project-scoped workflows

## Requirements

- PHP 8.3+
- Laravel 12+
- Filament 4+
- `codenzia/filament-diagrammer`

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
```

### 3. Embed the designer in a page

```blade
@livewire(
    \Codenzia\FilamentWorkflow\Pages\WorkflowDesigner::class,
    ['projectId' => $project->id, 'modelType' => Task::class]
)
```

## Configuration

Published to `config/filament-workflow.php`:

```php
return [
    // Queue name for automation jobs
    'queue' => 'automations',

    // Max nodes per workflow run (infinite loop prevention)
    'max_nodes_per_run' => 50,

    // Time trigger check interval (minutes)
    'time_trigger_interval' => 15,

    // Hours before same trigger can re-fire on same model
    'dedup_window_hours' => 24,

    // Days to keep execution logs
    'log_retention_days' => 90,
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

Implement `TriggerInterface`:

```php
use Codenzia\FilamentWorkflow\Engine\Contracts\TriggerInterface;
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
}
```

### Custom Actions

Implement `ActionHandlerInterface`:

```php
use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Illuminate\Database\Eloquent\Model;

class CloseParentAction implements ActionHandlerInterface
{
    public static function label(): string
    {
        return 'Close Parent Task';
    }

    public function execute(Model $model, array $config, array $context): array
    {
        $parent = $model->parent;
        if (! $parent) {
            return ['error' => 'No parent found'];
        }

        $parent->update(['status' => 'closed']);

        return ['action' => 'close_parent', 'parent_id' => $parent->id];
    }
}
```

### Register in ServiceProvider

```php
WorkflowEngine::registerTrigger('task.status_changed', TaskStatusChangedTrigger::class);
WorkflowEngine::registerAction('close_parent', CloseParentAction::class);
```

## Time-Based Triggers

For triggers based on due dates or overdue status, schedule the command:

```php
// routes/console.php
Schedule::command('workflow:process-time-triggers')->everyFifteenMinutes();
```

The command finds active workflows with time-based trigger nodes, queries matching models, and dispatches evaluation jobs. Deduplication prevents the same model+workflow from re-triggering within the configured window (default: 24 hours).

### Time Trigger Query Scoping

For time-based triggers to work with the preview and scheduler, implement the optional `scopeMatchingModels` static method on your trigger class:

```php
class TaskOverdueTrigger implements TriggerInterface
{
    // ... matches() method ...

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
- Color-coded execution log (green = success, red = failure, blue = delayed, gray = skipped)
- Node label, model ID, and relative timestamp for each execution
- Last 10 executions shown inline, up to 50 available via scroll

## Execution Logs

Every node execution is logged to `workflow_execution_logs` with:

- Workflow and node IDs
- Model type and ID
- Trigger type
- Result (success, failure, skipped, delayed)
- Execution details (what changed, what failed, etc.)
- Timestamp

## Infinite Loop Prevention

The engine uses two mechanisms:

1. **Static `$executing` flag** — prevents re-entrant execution when model changes trigger new evaluations
2. **Max nodes per run** — limits the number of nodes executed in a single workflow run (default: 50)

## Database Schema

### `workflows`
Stores workflow definitions with name, model type, project scope, status, and priority.

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
