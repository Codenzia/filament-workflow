<?php

declare(strict_types=1);

/**
 * ExecuteDelayedNodeJob
 *
 * Resumes workflow execution at a specific node after a delay.
 * Dispatched by the NodeExecutor when it encounters a delay node.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Jobs;

use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ExecuteDelayedNodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        protected int $nodeId,
        protected string $modelClass,
        protected int $modelId,
        protected array $context = [],
    ) {
        $this->queue = config('filament-workflow.queue', 'automations');
    }

    public function handle(WorkflowEngine $engine): void
    {
        $engine->executeNodeById($this->nodeId, $this->modelClass, $this->modelId, $this->context);
    }
}
