<?php

declare(strict_types=1);

/**
 * ProcessTimeTriggersCommand
 *
 * Scheduled command that checks for time-based triggers (overdue tasks,
 * approaching due dates) and dispatches workflow evaluation jobs.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Commands;

use Codenzia\FilamentWorkflow\Engine\Contracts\TimeTriggerInterface;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Jobs\EvaluateWorkflowJob;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProcessTimeTriggersCommand extends Command
{
    protected $signature = 'workflow:process-time-triggers {--workflow= : Limit processing to a single workflow id}';

    protected $description = 'Process time-based workflow triggers (due dates, overdue, etc.)';

    public function handle(): int
    {
        $onlyWorkflowId = $this->option('workflow');

        $workflows = Workflow::query()
            ->active()
            ->when($onlyWorkflowId, fn ($q) => $q->whereKey($onlyWorkflowId))
            ->whereHas('nodes', function ($q): void {
                $q->where('node_type', 'trigger')
                    ->where('type_config', 'like', 'time.%');
            })
            ->with(['nodes' => function ($q): void {
                $q->where('node_type', 'trigger')
                    ->where('type_config', 'like', 'time.%');
            }])
            ->get();

        $dedupHours = (int) config('filament-workflow.dedup_window_hours', 24);
        $chunkSize = (int) config('filament-workflow.time_trigger_chunk_size', 500);
        $processed = 0;

        foreach ($workflows as $workflow) {
            $modelClass = $workflow->model_type;
            if (! class_exists($modelClass)) {
                continue;
            }

            foreach ($workflow->nodes as $triggerNode) {
                $triggerType = $triggerNode->type_config;
                $config = $triggerNode->config ?? [];

                // Get matching models (implementation depends on the specific trigger)
                $candidates = $this->findMatchingModels($modelClass, $triggerType, $config);

                if ($candidates === null) {
                    continue;
                }

                // Chunked so a large candidate set never loads at once, and
                // the deduplication lookup runs once per chunk instead of
                // once per model.
                $candidates->chunkById($chunkSize, function (Collection $chunk) use ($workflow, $modelClass, $triggerType, $config, $dedupHours, &$processed): void {
                    $alreadyRun = $this->recentlyAccepted($workflow->id, $modelClass, $chunk->modelKeys(), $dedupHours);

                    foreach ($chunk as $model) {
                        if (isset($alreadyRun[$model->getKey()])) {
                            continue;
                        }

                        EvaluateWorkflowJob::dispatch(
                            $modelClass,
                            $model->getKey(),
                            $triggerType,
                            ['event' => 'time_trigger', 'trigger_config' => $config],
                            $model->project_id ?? null,
                            // This node came due for THIS workflow. Without the
                            // id the job would re-evaluate every workflow that
                            // shares the trigger type, so several trigger
                            // configs fan out into overlapping evaluations.
                            $workflow->id,
                        );

                        $processed++;
                    }
                });
            }
        }

        $this->info("Processed {$processed} time-based triggers.");

        $this->pruneExecutionLogs();

        return self::SUCCESS;
    }

    /**
     * Delete execution logs older than the configured retention window.
     * No-op when log_retention_days is null.
     */
    protected function pruneExecutionLogs(): void
    {
        $days = config('filament-workflow.log_retention_days');
        if ($days === null) {
            return;
        }

        WorkflowExecutionLog::where('executed_at', '<', now()->subDays((int) $days))->delete();
    }

    /**
     * Model keys that already produced an accepted run for this workflow
     * inside the deduplication window.
     *
     * Only results the engine actually acted on suppress a new dispatch. A
     * skipped or failed attempt leaves the occurrence eligible, so a
     * transient failure is not swallowed for the whole window.
     *
     * @param  array<int, mixed>  $modelIds
     * @return array<int|string, true>
     */
    protected function recentlyAccepted(int $workflowId, string $modelClass, array $modelIds, int $dedupHours): array
    {
        if ($modelIds === []) {
            return [];
        }

        return array_fill_keys(
            WorkflowExecutionLog::query()
                ->where('workflow_id', $workflowId)
                ->where('model_type', $modelClass)
                ->whereIn('model_id', $modelIds)
                ->whereIn('result', ['success', 'delayed'])
                ->where('executed_at', '>=', now()->subHours($dedupHours))
                ->pluck('model_id')
                ->all(),
            true,
        );
    }

    /**
     * Build the query of models matching a time-based trigger, or null when
     * the trigger type is not registered. Returning the query (rather than
     * its results) lets the caller chunk it.
     */
    protected function findMatchingModels(string $modelClass, string $triggerType, array $config): ?Builder
    {
        $query = $modelClass::query();

        // Registered time triggers handle the query building.
        $triggers = WorkflowEngine::getTriggers();
        $triggerClass = $triggers[$triggerType] ?? null;

        if (! $triggerClass) {
            return null;
        }

        // Resolve an INSTANCE and call it — never call a (possibly non-static)
        // method statically on the class string, which would raise a PHP 8
        // "cannot be called statically" Error for conventionally-written
        // triggers and abort the whole scheduled command.
        $trigger = app($triggerClass);

        if ($trigger instanceof TimeTriggerInterface) {
            return $trigger->matchingModels($query, $config);
        }

        // Default: nothing to process for this trigger type.
        return null;
    }
}
