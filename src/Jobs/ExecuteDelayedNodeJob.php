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
use Codenzia\FilamentWorkflow\Exceptions\WorkflowLockTimeoutException;
use Codenzia\FilamentWorkflow\Jobs\Concerns\HandlesLockContention;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ExecuteDelayedNodeJob implements ShouldQueue
{
    use Dispatchable, HandlesLockContention, InteractsWithQueue, Queueable;

    /**
     * Attempts exist so a resume blocked by the per-model lock can be put
     * back on the queue (see handle()). A resume that throws is not
     * replayed — $maxExceptions = 1 fails it immediately so the nodes it
     * already executed cannot run a second time.
     */
    public int $tries = 5;

    public int $maxExceptions = 1;

    /**
     * @param  array  $run  The originating run's continuation payload: its id,
     *                      start time, hop count and already-visited nodes.
     *                      Keeps a delayed resume part of the same run so
     *                      cycles and budgets survive the delay.
     */
    public function __construct(
        protected int $nodeId,
        protected string $modelClass,
        protected int $modelId,
        protected array $context = [],
        protected array $run = [],
    ) {
        $this->queue = config('filament-workflow.queue', 'automations');
    }

    public function handle(WorkflowEngine $engine): void
    {
        try {
            $engine->executeNodeById($this->nodeId, $this->modelClass, $this->modelId, $this->context, $this->run);
        } catch (WorkflowLockTimeoutException) {
            $this->release($this->contentionDelay());
        }
    }
}
