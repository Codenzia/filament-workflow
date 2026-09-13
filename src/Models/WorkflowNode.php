<?php

declare(strict_types=1);

/**
 * WorkflowNode Model
 *
 * Represents a single node in a workflow (trigger, condition, delay, or action).
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Models;

use Codenzia\FilamentWorkflow\Enums\NodeTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class WorkflowNode extends Model
{
    protected $fillable = [
        'workflow_id',
        'node_type',
        'type_config',
        'config',
        'position_x',
        'position_y',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'node_type' => NodeTypeEnum::class,
            'config' => 'array',
            'position_x' => 'float',
            'position_y' => 'float',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Connections where this node is the source (outgoing).
     */
    public function outgoingConnections(): HasMany
    {
        return $this->hasMany(WorkflowConnection::class, 'source_node_id');
    }

    /**
     * Connections where this node is the target (incoming).
     */
    public function incomingConnections(): HasMany
    {
        return $this->hasMany(WorkflowConnection::class, 'target_node_id');
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Get a config value by key with an optional default.
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Get the next nodes connected via outgoing connections.
     * Optionally filtered by connection label (e.g., 'Yes' or 'No' for conditions).
     */
    /**
     * @return Collection<int, WorkflowNode>
     */
    public function getNextNodes(?string $connectionLabel = null): Collection
    {
        $query = $this->outgoingConnections()->with('targetNode');

        if ($connectionLabel !== null) {
            $query->where('label', $connectionLabel);
        }

        return $query->orderBy('sort_order')->get()->pluck('targetNode');
    }
}
