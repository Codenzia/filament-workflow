<?php

declare(strict_types=1);

/**
 * WorkflowExecutionLog Model
 *
 * Records the result of each node execution during a workflow run.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkflowExecutionLog extends Model
{
    protected $fillable = [
        'workflow_id',
        'workflow_node_id',
        'model_type',
        'model_id',
        'trigger_type',
        'result',
        'details',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'workflow_node_id');
    }

    /**
     * The model that triggered this execution.
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
