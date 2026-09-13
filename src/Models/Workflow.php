<?php

declare(strict_types=1);

/**
 * Workflow Model
 *
 * Represents a workflow automation definition with its nodes and connections.
 * Can be scoped to a project or global (project_id = null).
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Models;

use Codenzia\FilamentWorkflow\Enums\WorkflowStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    protected $fillable = [
        'name',
        'description',
        'model_type',
        'project_id',
        'status',
        'priority',
        'canvas_data',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'draft',
        'priority' => 0,
        'run_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatusEnum::class,
            'canvas_data' => 'array',
            'last_run_at' => 'datetime',
            'priority' => 'integer',
            'run_count' => 'integer',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowNode::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(WorkflowConnection::class);
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(WorkflowExecutionLog::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\\Models\\User'), 'created_by');
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', WorkflowStatusEnum::ACTIVE);
    }

    /**
     * Find workflows that match a trigger type.
     * Searches for trigger nodes with the given type_config.
     */
    public function scopeForTrigger(Builder $query, string $triggerType): Builder
    {
        return $query->whereHas('nodes', function (Builder $q) use ($triggerType): void {
            $q->where('node_type', 'trigger')
                ->where('type_config', $triggerType);
        });
    }

    /**
     * Find workflows scoped to a project or global (project_id = null).
     */
    public function scopeForProject(Builder $query, ?int $projectId): Builder
    {
        return $query->where(function (Builder $q) use ($projectId): void {
            $q->whereNull('project_id');
            if ($projectId !== null) {
                $q->orWhere('project_id', $projectId);
            }
        });
    }

    public function scopeForModelType(Builder $query, string $modelType): Builder
    {
        return $query->where('model_type', $modelType);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Get the trigger node(s) for this workflow.
     */
    public function getTriggerNodes(): Collection
    {
        return $this->nodes()->where('node_type', 'trigger')->get();
    }

    /**
     * Record a successful run.
     */
    public function recordRun(): void
    {
        // Single write: increment() accepts extra column updates as its third arg.
        $this->increment('run_count', 1, ['last_run_at' => now()]);
    }
}
