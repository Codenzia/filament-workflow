<?php

declare(strict_types=1);

/**
 * WorkflowEngine
 *
 * Main orchestrator for workflow automation. Finds matching workflows,
 * walks the node graph, evaluates conditions, and executes actions.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Engine;

use Codenzia\FilamentWorkflow\Enums\NodeTypeEnum;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Illuminate\Database\Eloquent\Model;

class WorkflowEngine
{
    /**
     * Infinite loop prevention: when true, the engine is already evaluating
     * and should not re-enter from model observer side-effects.
     */
    protected static bool $executing = false;

    /**
     * Registered trigger types: key => class-string<TriggerInterface>
     */
    protected static array $triggers = [];

    /**
     * Registered action types: key => class-string<ActionHandlerInterface>
     */
    protected static array $actions = [];

    protected NodeExecutor $nodeExecutor;

    public function __construct()
    {
        $this->nodeExecutor = new NodeExecutor(new ConditionEvaluator);
    }

    // ─── Registration API ───────────────────────────────────────────

    public static function registerTrigger(string $key, string $class): void
    {
        static::$triggers[$key] = $class;
    }

    public static function registerAction(string $key, string $class): void
    {
        static::$actions[$key] = $class;
    }

    public static function getTriggers(): array
    {
        return static::$triggers;
    }

    public static function getActions(): array
    {
        return static::$actions;
    }

    /**
     * Clear all registered triggers and actions (useful for testing).
     */
    public static function clearRegistrations(): void
    {
        static::$triggers = [];
        static::$actions = [];
    }

    // ─── Execution ──────────────────────────────────────────────────

    public static function isExecuting(): bool
    {
        return static::$executing;
    }

    /**
     * Evaluate all active workflows matching the given trigger for a model.
     *
     * Finds workflows scoped to the model's project (if applicable) plus global
     * workflows, evaluates conditions, and executes actions.
     *
     * @param  Model   $model       The model that triggered the workflow
     * @param  string  $triggerType  The trigger type key (e.g., 'task.status_changed')
     * @param  array   $context     Additional context (e.g., ['from' => 'active', 'to' => 'closed'])
     * @param  ?int    $projectId   Optional project scope
     */
    public function evaluate(Model $model, string $triggerType, array $context = [], ?int $projectId = null): void
    {
        // Prevent re-entrant execution (infinite loops)
        if (static::$executing) {
            return;
        }

        static::$executing = true;

        try {
            $workflows = Workflow::query()
                ->active()
                ->forModelType($model::class)
                ->forTrigger($triggerType)
                ->forProject($projectId)
                ->orderBy('priority', 'desc')
                ->with(['nodes', 'connections'])
                ->get();

            foreach ($workflows as $workflow) {
                $this->executeWorkflow($workflow, $model, $triggerType, $context);
            }
        } finally {
            static::$executing = false;
        }
    }

    /**
     * Execute a single workflow for a model.
     */
    public function executeWorkflow(Workflow $workflow, Model $model, string $triggerType, array $context): void
    {
        $maxNodes = config('filament-workflow.max_nodes_per_run', 50);
        $executedCount = 0;

        // Find the trigger node
        $triggerNodes = $workflow->nodes->where('node_type', NodeTypeEnum::TRIGGER);

        foreach ($triggerNodes as $triggerNode) {
            $result = $this->nodeExecutor->execute(
                $triggerNode,
                $model,
                $context,
                static::$triggers,
                static::$actions,
            );

            $this->logExecution($workflow, $triggerNode, $model, $triggerType, $result);
            $executedCount++;

            if ($result['result'] !== 'success') {
                continue;
            }

            // Walk the graph from the trigger node
            $nextNodes = $triggerNode->getNextNodes();
            $this->walkGraph($workflow, $nextNodes, $model, $triggerType, $context, $executedCount, $maxNodes);
        }

        $workflow->recordRun();
    }

    /**
     * Walk the workflow graph recursively, executing each node.
     */
    protected function walkGraph(
        Workflow $workflow,
        \Illuminate\Support\Collection $nodes,
        Model $model,
        string $triggerType,
        array $context,
        int &$executedCount,
        int $maxNodes,
    ): void {
        foreach ($nodes as $node) {
            if ($executedCount >= $maxNodes) {
                $this->logExecution($workflow, $node, $model, $triggerType, [
                    'result' => 'skipped',
                    'branch' => null,
                    'details' => ['reason' => 'Max node execution limit reached'],
                ]);

                return;
            }

            $result = $this->nodeExecutor->execute(
                $node,
                $model,
                $context,
                static::$triggers,
                static::$actions,
            );

            $this->logExecution($workflow, $node, $model, $triggerType, $result);
            $executedCount++;

            // Stop walking on delay (delayed job will resume) or failure
            if ($result['result'] === 'delayed' || $result['result'] === 'failure') {
                continue;
            }

            // For condition nodes, follow the matching branch
            if ($result['branch'] !== null) {
                $nextNodes = $node->getNextNodes($result['branch']);
            } else {
                $nextNodes = $node->getNextNodes();
            }

            if ($nextNodes->isNotEmpty()) {
                $this->walkGraph($workflow, $nextNodes, $model, $triggerType, $context, $executedCount, $maxNodes);
            }
        }
    }

    /**
     * Execute a single node by ID (used by delayed job to resume after delay).
     */
    public function executeNodeById(int $nodeId, string $modelClass, int $modelId, array $context): void
    {
        $node = WorkflowNode::with('workflow')->find($nodeId);
        if (! $node) {
            return;
        }

        /** @var Model|null $model */
        $model = $modelClass::find($modelId);
        if (! $model) {
            return;
        }

        static::$executing = true;

        try {
            $workflow = $node->workflow;
            $maxNodes = config('filament-workflow.max_nodes_per_run', 50);
            $executedCount = 0;

            $result = $this->nodeExecutor->execute(
                $node,
                $model,
                $context,
                static::$triggers,
                static::$actions,
            );

            $this->logExecution($workflow, $node, $model, 'delayed_resume', $result);
            $executedCount++;

            if ($result['result'] !== 'failure' && $result['result'] !== 'delayed') {
                $nextNodes = $result['branch'] !== null
                    ? $node->getNextNodes($result['branch'])
                    : $node->getNextNodes();

                if ($nextNodes->isNotEmpty()) {
                    $this->walkGraph($workflow, $nextNodes, $model, 'delayed_resume', $context, $executedCount, $maxNodes);
                }
            }
        } finally {
            static::$executing = false;
        }
    }

    /**
     * Log a node execution result.
     */
    protected function logExecution(
        Workflow $workflow,
        WorkflowNode $node,
        Model $model,
        string $triggerType,
        array $result,
    ): void {
        WorkflowExecutionLog::create([
            'workflow_id' => $workflow->id,
            'workflow_node_id' => $node->id,
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
            'trigger_type' => $triggerType,
            'result' => $result['result'],
            'details' => $result['details'] ?? [],
            'executed_at' => now(),
        ]);
    }
}
