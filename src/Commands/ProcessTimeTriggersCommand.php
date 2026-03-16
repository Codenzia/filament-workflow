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

use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Codenzia\FilamentWorkflow\Jobs\EvaluateWorkflowJob;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Illuminate\Console\Command;

class ProcessTimeTriggersCommand extends Command
{
    protected $signature = 'workflow:process-time-triggers';

    protected $description = 'Process time-based workflow triggers (due dates, overdue, etc.)';

    public function handle(): int
    {
        $workflows = Workflow::query()
            ->active()
            ->whereHas('nodes', function ($q): void {
                $q->where('node_type', 'trigger')
                    ->where('type_config', 'like', 'time.%');
            })
            ->with(['nodes' => function ($q): void {
                $q->where('node_type', 'trigger')
                    ->where('type_config', 'like', 'time.%');
            }])
            ->get();

        $dedupHours = config('filament-workflow.dedup_window_hours', 24);
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
                $models = $this->findMatchingModels($modelClass, $triggerType, $config);

                foreach ($models as $model) {
                    // Deduplication check
                    $recentLog = WorkflowExecutionLog::where('workflow_id', $workflow->id)
                        ->where('model_type', $modelClass)
                        ->where('model_id', $model->getKey())
                        ->where('executed_at', '>=', now()->subHours($dedupHours))
                        ->exists();

                    if ($recentLog) {
                        continue;
                    }

                    $projectId = $model->project_id ?? null;

                    EvaluateWorkflowJob::dispatch(
                        $modelClass,
                        $model->getKey(),
                        $triggerType,
                        ['event' => 'time_trigger', 'trigger_config' => $config],
                        $projectId,
                    );

                    $processed++;
                }
            }
        }

        $this->info("Processed {$processed} time-based triggers.");

        return self::SUCCESS;
    }

    /**
     * Find models matching a time-based trigger.
     */
    protected function findMatchingModels(string $modelClass, string $triggerType, array $config): \Illuminate\Database\Eloquent\Collection
    {
        $query = $modelClass::query();

        // Registered time triggers handle the query building
        $triggers = WorkflowEngine::getTriggers();
        $triggerClass = $triggers[$triggerType] ?? null;

        if ($triggerClass && method_exists($triggerClass, 'scopeMatchingModels')) {
            return $triggerClass::scopeMatchingModels($query, $config)->get();
        }

        // Default: return empty collection (no matching models)
        return collect();
    }
}
