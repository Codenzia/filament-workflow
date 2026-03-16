<?php

declare(strict_types=1);

/**
 * WorkflowConnection Model
 *
 * Represents a directed edge between two workflow nodes.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowConnection extends Model
{
    protected $fillable = [
        'workflow_id',
        'source_node_id',
        'target_node_id',
        'label',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'source_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'target_node_id');
    }
}
