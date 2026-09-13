<?php

declare(strict_types=1);

/**
 * EvaluateWorkflowJob
 *
 * Queued job that evaluates all matching workflows for a model event.
 * Dispatched by the HasWorkflows trait's model observer.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Jobs;

use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Exceptions\WorkflowLockTimeoutException;
use Codenzia\FilamentWorkflow\Jobs\Concerns\HandlesLockContention;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateWorkflowJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, HandlesLockContention, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Attempts exist only so a run blocked by the per-model lock can be put
     * back on the queue (see handle()). Real failures are still never
     * replayed: $maxExceptions = 1 fails the job on the first uncaught
     * throwable, so a workflow action that mutates DB state cannot produce
     * duplicate side effects. Failed runs land in failed_jobs.
     */
    public int $tries = 5;

    public int $maxExceptions = 1;

    public function __construct(
        protected string $modelClass,
        protected int $modelId,
        protected string $triggerType,
        protected array $context = [],
        protected ?int $projectId = null,
        protected ?int $workflowId = null,
    ) {
        $this->queue = config('filament-workflow.queue', 'automations');
    }

    /**
     * Deduplicate concurrent dispatches with identical (model, trigger, context).
     * Prevents the observer-storm scenario where rapid model updates queue
     * many copies of the same evaluation while the first is still pending.
     */
    public function uniqueId(): string
    {
        return $this->modelClass.':'.$this->modelId.':'.$this->triggerType.':'.($this->workflowId ?? 'all').':'.md5(serialize($this->context));
    }

    public function uniqueFor(): int
    {
        return (int) config('filament-workflow.dedup_dispatch_seconds', 60);
    }

    public function handle(WorkflowEngine $engine): void
    {
        /** @var Model|null $model */
        $model = $this->modelClass::find($this->modelId);
        if (! $model) {
            return;
        }

        try {
            $engine->evaluate($model, $this->triggerType, $this->context, $this->projectId, $this->workflowId);
        } catch (WorkflowLockTimeoutException) {
            // Another worker is running workflows for this record. The event
            // is still due — put it back on the queue rather than losing it.
            $this->release($this->contentionDelay());
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("filament-workflow: EvaluateWorkflowJob failed for {$this->modelClass}:{$this->modelId} trigger={$this->triggerType} — {$e->getMessage()}");
    }
}
