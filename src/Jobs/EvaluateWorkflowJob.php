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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $modelClass,
        protected int $modelId,
        protected string $triggerType,
        protected array $context = [],
        protected ?int $projectId = null,
    ) {
        $this->queue = config('filament-workflow.queue', 'automations');
    }

    public function handle(WorkflowEngine $engine): void
    {
        /** @var \Illuminate\Database\Eloquent\Model|null $model */
        $model = $this->modelClass::find($this->modelId);
        if (! $model) {
            return;
        }

        $engine->evaluate($model, $this->triggerType, $this->context, $this->projectId);
    }
}
