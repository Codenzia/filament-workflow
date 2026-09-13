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
use Codenzia\FilamentWorkflow\Enums\WorkflowStatusEnum;
use Codenzia\FilamentWorkflow\Exceptions\WorkflowLockTimeoutException;
use Codenzia\FilamentWorkflow\Exceptions\WorkflowRolledBackException;
use Codenzia\FilamentWorkflow\Models\Workflow;
use Codenzia\FilamentWorkflow\Models\WorkflowExecutionLog;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkflowEngine
{
    /**
     * Models currently being evaluated in this PHP process, keyed by
     * "ClassName:id". Acts as the in-process re-entrance guard so an
     * action's $model->update() does not spawn nested workflow runs on
     * the same model. Cross-model triggers from a workflow's actions
     * are NOT blocked.
     *
     * @var array<string, true>
     */
    protected static array $executingModels = [];

    /**
     * Registered trigger types: key => class-string<TriggerInterface>
     */
    protected static array $triggers = [];

    /**
     * Registered action types: key => class-string<ActionHandlerInterface>
     */
    protected static array $actions = [];

    /**
     * Registered model field options: model class => [field_name => label, ...]
     * Used by condition nodes, triggers, and actions to show field dropdowns.
     */
    protected static array $modelFields = [];

    /**
     * Allow-listed event classes the engine may instantiate from node config.
     * Keyed by FQCN. Augmented by config('filament-workflow.allowed_event_classes').
     */
    protected static array $eventClasses = [];

    /**
     * Allow-listed notification classes the engine may instantiate from node config.
     * Keyed by FQCN. Augmented by config('filament-workflow.allowed_notification_classes').
     */
    protected static array $notificationClasses = [];

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

    /**
     * Allow-list an event class for DispatchEventAction.
     */
    public static function registerEventClass(string $fqcn): void
    {
        static::$eventClasses[$fqcn] = $fqcn;
    }

    /**
     * Allow-list a notification class for SendNotificationAction / EscalateOnDeadlineAction.
     */
    public static function registerNotificationClass(string $fqcn): void
    {
        static::$notificationClasses[$fqcn] = $fqcn;
    }

    /**
     * All allow-listed event classes (runtime registrations merged with config).
     *
     * @return array<string, string>
     */
    public static function getAllowedEventClasses(): array
    {
        $configured = (array) config('filament-workflow.allowed_event_classes', []);

        return array_merge(
            static::$eventClasses,
            array_combine($configured, $configured) ?: [],
        );
    }

    /**
     * All allow-listed notification classes (runtime registrations merged with config).
     *
     * @return array<string, string>
     */
    public static function getAllowedNotificationClasses(): array
    {
        $configured = (array) config('filament-workflow.allowed_notification_classes', []);

        return array_merge(
            static::$notificationClasses,
            array_combine($configured, $configured) ?: [],
        );
    }

    public static function isAllowedEvent(string $fqcn): bool
    {
        return isset(static::getAllowedEventClasses()[$fqcn]);
    }

    public static function isAllowedNotification(string $fqcn): bool
    {
        return isset(static::getAllowedNotificationClasses()[$fqcn]);
    }

    public static function getActions(): array
    {
        return static::$actions;
    }

    /**
     * Register available model fields for use in condition/trigger/action config dropdowns.
     *
     * @param  string  $modelClass  e.g., 'App\Models\Task'
     * @param  array<string, string>  $fields  e.g., ['status' => 'Status', 'priority' => 'Priority']
     */
    public static function registerModelFields(string $modelClass, array $fields): void
    {
        static::$modelFields[$modelClass] = $fields;
    }

    /**
     * Get registered fields for a model class, or the designer-wide catalog
     * when no class is given. Returns a flat [field_name => label] array.
     *
     * A model class with no registration returns an EMPTY array — it never
     * inherits the fields registered for other models. Callers that use this
     * as a read/write allow-list would otherwise let a field registered on
     * one model become writable on an unrelated one.
     *
     * @return array<string, string>
     */
    public static function getModelFields(?string $modelClass = null): array
    {
        if ($modelClass !== null) {
            return static::$modelFields[$modelClass] ?? [];
        }

        // No class asked for: merge every registration for designer dropdowns.
        $merged = [];
        foreach (static::$modelFields as $fields) {
            $merged = array_merge($merged, $fields);
        }

        return $merged;
    }

    /**
     * Clear all registered triggers and actions (useful for testing).
     */
    public static function clearRegistrations(): void
    {
        static::$triggers = [];
        static::$actions = [];
        static::$modelFields = [];
        static::$eventClasses = [];
        static::$notificationClasses = [];
    }

    // ─── Execution ──────────────────────────────────────────────────

    /**
     * Whether the engine is currently evaluating workflows.
     *
     * Pass a model to ask whether THAT specific model is being evaluated;
     * pass nothing for the broad "any model" check (kept for backward compat).
     */
    public static function isExecuting(?Model $model = null): bool
    {
        if ($model === null) {
            return ! empty(static::$executingModels);
        }

        return isset(static::$executingModels[static::modelKey($model)]);
    }

    protected static function modelKey(Model $model): string
    {
        return $model::class.':'.(string) $model->getKey();
    }

    /**
     * Evaluate all active workflows matching the given trigger for a model.
     *
     * Finds workflows scoped to the model's project (if applicable) plus global
     * workflows, evaluates conditions, and executes actions.
     *
     * @param  Model  $model  The model that triggered the workflow
     * @param  string  $triggerType  The trigger type key (e.g., 'task.status_changed')
     * @param  array  $context  Additional context (e.g., ['from' => 'active', 'to' => 'closed'])
     * @param  ?int  $projectId  Optional project scope
     * @param  ?int  $workflowId  Evaluate only this workflow. Used by the
     *                            time-trigger scheduler, which already knows
     *                            which workflow's trigger node came due and
     *                            must not fan out into every other workflow
     *                            sharing the trigger type.
     *
     * @throws WorkflowLockTimeoutException When another process holds the
     *                                      per-model lock. Queued callers
     *                                      release themselves and retry.
     */
    public function evaluate(Model $model, string $triggerType, array $context = [], ?int $projectId = null, ?int $workflowId = null): void
    {
        $modelKey = static::modelKey($model);

        // In-process re-entrance guard: a workflow action just mutated this
        // model — skip nested run on the same model in the same process.
        if (isset(static::$executingModels[$modelKey])) {
            return;
        }

        // Cross-process serialization: prevent two workers from running
        // workflows on the same model concurrently. Block briefly to allow
        // legitimate sequential triggers; on timeout, hand the decision back
        // to the caller — the queued jobs re-queue the event with backoff so
        // contention delays the work instead of discarding it.
        $lock = Cache::lock(
            'filament-workflow:lock:'.md5($modelKey),
            (int) config('filament-workflow.lock_ttl', 60),
        );

        try {
            $lock->block((int) config('filament-workflow.lock_wait_seconds', 10));
        } catch (LockTimeoutException) {
            Log::warning("filament-workflow: lock contention timeout for {$modelKey}, trigger={$triggerType}");

            throw new WorkflowLockTimeoutException("Could not acquire workflow lock for {$modelKey}, trigger={$triggerType}");
        }

        static::$executingModels[$modelKey] = true;

        try {
            $workflows = Workflow::query()
                ->active()
                ->forModelType($model::class)
                ->forTrigger($triggerType)
                ->forProject($projectId)
                ->when($workflowId !== null, fn ($q) => $q->whereKey($workflowId))
                ->orderBy('priority', 'desc')
                ->with(['nodes', 'connections'])
                ->get();

            foreach ($workflows as $workflow) {
                $this->executeWorkflow($workflow, $model, $triggerType, $context);
            }
        } finally {
            unset(static::$executingModels[$modelKey]);
            $lock->release();
        }
    }

    /**
     * Execute a single workflow for a model.
     *
     * The whole run is wrapped in a DB transaction. Any action that
     * returns 'failure' rolls back the entire chain — successful earlier
     * actions are NOT partially committed. This mirrors the behaviour of
     * the production ApprovalEngine and prevents half-applied workflow
     * runs (e.g., assigned user without notification sent).
     */
    public function executeWorkflow(Workflow $workflow, Model $model, string $triggerType, array $context): void
    {
        $maxNodes = (int) config('filament-workflow.max_nodes_per_run', 50);
        $hadSuccess = false;

        // Durable identity for this run. Delay nodes hand it to their queued
        // continuation so the visited set, node budget and start time survive
        // the delay instead of resetting on resume.
        $run = WorkflowRun::start();

        // Build the adjacency map once from the already eager-loaded graph
        // so walkGraph can resolve next nodes in-memory instead of issuing a
        // fresh connection query per executed node (avoids an intra-run N+1).
        $graph = $this->buildGraph($workflow);

        try {
            DB::transaction(function () use ($workflow, $model, $triggerType, $context, $maxNodes, $graph, $run, &$hadSuccess): void {
                $hadFailure = false;

                $triggerNodes = $workflow->nodes->where('node_type', NodeTypeEnum::TRIGGER);

                foreach ($triggerNodes as $triggerNode) {
                    $result = $this->nodeExecutor->execute(
                        $triggerNode,
                        $model,
                        $context,
                        static::$triggers,
                        static::$actions,
                        $run,
                    );

                    $this->logExecution($workflow, $triggerNode, $model, $triggerType, $result, $run);
                    $run->markVisited($triggerNode->id);

                    if ($result['result'] === 'failure') {
                        $hadFailure = true;

                        continue;
                    }

                    if ($result['result'] !== 'success') {
                        continue;
                    }

                    $hadSuccess = true;

                    $nextNodes = $this->nextNodesFromGraph($graph, $triggerNode);
                    $this->walkGraph($workflow, $graph, $nextNodes, $model, $triggerType, $context, $run, $maxNodes, $hadFailure, $hadSuccess);
                }

                if ($hadFailure) {
                    // Throw a sentinel to abort the transaction; logged execution rows
                    // are themselves part of this transaction and will roll back along
                    // with the action side-effects, so we re-log a single failure marker
                    // outside the transaction in the catch below.
                    throw new WorkflowRolledBackException(
                        "Workflow {$workflow->id} rolled back due to action failure",
                    );
                }
            });
        } catch (WorkflowRolledBackException $e) {
            Log::warning('filament-workflow: '.$e->getMessage());

            // The execution log rows from the rolled-back run are also gone.
            // Persist a single audit row so the rollback is visible in the UI.
            WorkflowExecutionLog::create([
                'workflow_id' => $workflow->id,
                'workflow_node_id' => null,
                'model_type' => $model::class,
                'model_id' => $model->getKey(),
                'trigger_type' => $triggerType,
                'result' => 'failure',
                'details' => ['reason' => 'rolled_back', 'message' => $e->getMessage(), 'run_id' => $run->id],
                'executed_at' => now(),
            ]);

            // The database is back to its pre-run state but the in-memory
            // model still carries the writes the rolled-back actions made.
            // Discard them before the next workflow evaluates conditions or
            // notifies anyone from state that was never committed.
            $this->refreshAfterRollback($model);

            // A rolled-back run is not a successful run — keep recordRun() honest.
            $hadSuccess = false;
        }

        // Only count this as a "run" for dashboard metrics if at least one
        // node returned success. Skipped-only runs (no trigger matched, all
        // conditions false) and rolled-back failures don't pollute the
        // run count or "Last Run At" timestamp.
        if ($hadSuccess) {
            $workflow->recordRun();
        }
    }

    /**
     * Walk the workflow graph recursively, executing each node.
     *
     * @param  WorkflowRun  $run  Carries the run's visited-node set, which
     *                            prevents cycles where a connection points
     *                            back to an upstream node — including cycles
     *                            that pass through a delay node, because the
     *                            same set travels with the queued resume.
     * @param  bool  $hadFailure  Set true if any action returns 'failure'.
     *                            Caller wraps the run in a transaction and
     *                            uses this flag to roll back.
     * @param  bool  $hadSuccess  Set true if any node returned 'success'.
     *                            Caller uses this to decide whether the run
     *                            counts toward the workflow's recordRun() stats.
     */
    protected function walkGraph(
        Workflow $workflow,
        array $graph,
        Collection $nodes,
        Model $model,
        string $triggerType,
        array $context,
        WorkflowRun $run,
        int $maxNodes,
        bool &$hadFailure,
        bool &$hadSuccess,
    ): void {
        foreach ($nodes as $node) {
            if ($run->executedCount() >= $maxNodes) {
                $this->logExecution($workflow, $node, $model, $triggerType, [
                    'result' => 'skipped',
                    'branch' => null,
                    'details' => ['reason' => 'Max node execution limit reached'],
                ], $run);

                return;
            }

            // Cycle detection: a node already executed in this run is skipped.
            // Logged once per cycle entry so the designer UI can flag the loop.
            if ($run->hasVisited($node->id)) {
                $this->logExecution($workflow, $node, $model, $triggerType, [
                    'result' => 'skipped',
                    'branch' => null,
                    'details' => ['reason' => 'Cycle detected: node already executed in this run'],
                ], $run);

                continue;
            }

            $result = $this->nodeExecutor->execute(
                $node,
                $model,
                $context,
                static::$triggers,
                static::$actions,
                $run,
            );

            $this->logExecution($workflow, $node, $model, $triggerType, $result, $run);
            $run->markVisited($node->id);

            if ($result['result'] === 'failure') {
                $hadFailure = true;

                continue;
            }

            if ($result['result'] === 'success') {
                $hadSuccess = true;
            }

            // Stop walking downstream on delay (delayed job will resume).
            if ($result['result'] === 'delayed') {
                continue;
            }

            // An action that reported an error did not do what the node says
            // it does. Its downstream nodes would be acting on an effect that
            // never happened, so the branch stops here. A deliberate skip
            // (a guard the action was configured with) still continues.
            if ($result['result'] === 'error') {
                continue;
            }

            // For condition nodes, follow the matching branch
            $nextNodes = $this->nextNodesFromGraph($graph, $node, $result['branch']);

            if ($nextNodes->isNotEmpty()) {
                $this->walkGraph($workflow, $graph, $nextNodes, $model, $triggerType, $context, $run, $maxNodes, $hadFailure, $hadSuccess);
            }
        }
    }

    /**
     * Build an in-memory representation of a workflow's graph from its
     * already eager-loaded nodes and connections.
     *
     * @return array{nodes: Collection, adjacency: Collection}
     */
    protected function buildGraph(Workflow $workflow): array
    {
        return [
            'nodes' => $workflow->nodes->keyBy('id'),
            'adjacency' => $workflow->connections->groupBy('source_node_id'),
        ];
    }

    /**
     * Resolve the next nodes for a source node from the in-memory graph,
     * preserving the existing semantics of WorkflowNode::getNextNodes():
     * label-matched edges when a branch is given, all edges otherwise,
     * ordered by sort_order, mapped to their target node models.
     *
     * @param  array{nodes: Collection, adjacency: Collection}  $graph
     * @return Collection<int, WorkflowNode>
     */
    protected function nextNodesFromGraph(array $graph, WorkflowNode $node, ?string $branch = null): Collection
    {
        $edges = $graph['adjacency']->get($node->id);

        if ($edges === null) {
            return new Collection;
        }

        return $edges
            ->when($branch !== null, fn ($c) => $c->where('label', $branch))
            ->sortBy('sort_order')
            ->map(fn ($conn) => $graph['nodes']->get($conn->target_node_id))
            ->filter()
            ->values();
    }

    /**
     * Execute a single node by ID (used by delayed job to resume after delay).
     *
     * @param  array  $runPayload  The originating run's continuation payload
     *                             (id, start time, hop count, visited nodes).
     *                             Empty starts a fresh run, so continuations
     *                             queued before run identity existed still run.
     *
     * @throws WorkflowLockTimeoutException When another process holds the
     *                                      per-model lock.
     */
    public function executeNodeById(int $nodeId, string $modelClass, int $modelId, array $context, array $runPayload = []): void
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

        $run = WorkflowRun::fromArray($runPayload);
        $modelKey = static::modelKey($model);

        // Cross-process serialization on resume too.
        $lock = Cache::lock(
            'filament-workflow:lock:'.md5($modelKey),
            (int) config('filament-workflow.lock_ttl', 60),
        );

        try {
            $lock->block((int) config('filament-workflow.lock_wait_seconds', 10));
        } catch (LockTimeoutException) {
            Log::warning("filament-workflow: lock contention timeout on delayed resume for {$modelKey}, node={$nodeId}");

            throw new WorkflowLockTimeoutException("Could not acquire workflow lock for {$modelKey} on delayed resume of node {$nodeId}");
        }

        static::$executingModels[$modelKey] = true;

        try {
            $workflow = $node->workflow;

            // The workflow may have been disabled or deleted during the delay.
            // Don't resume execution against an inactive workflow — log and exit.
            if (! $workflow || $workflow->status !== WorkflowStatusEnum::ACTIVE) {
                if ($workflow) {
                    $this->logExecution($workflow, $node, $model, 'delayed_resume', [
                        'result' => 'skipped',
                        'branch' => null,
                        'details' => ['reason' => 'Workflow no longer active on resume'],
                    ], $run);
                }

                return;
            }

            $maxNodes = (int) config('filament-workflow.max_nodes_per_run', 50);

            if ($this->runBudgetExhausted($workflow, $node, $model, $run, $maxNodes)) {
                return;
            }

            // Eager-load the graph once so downstream resolution is in-memory.
            $workflow->load(['nodes', 'connections']);
            $graph = $this->buildGraph($workflow);

            try {
                DB::transaction(function () use ($workflow, $graph, $node, $model, $context, $maxNodes, $run): void {
                    $hadFailure = false;
                    $hadSuccess = false;

                    $result = $this->nodeExecutor->execute(
                        $node,
                        $model,
                        $context,
                        static::$triggers,
                        static::$actions,
                        $run,
                    );

                    $this->logExecution($workflow, $node, $model, 'delayed_resume', $result, $run);
                    $run->markVisited($node->id);

                    if ($result['result'] === 'failure') {
                        $hadFailure = true;
                    } else {
                        if ($result['result'] === 'success') {
                            $hadSuccess = true;
                        }

                        if ($result['result'] !== 'delayed' && $result['result'] !== 'error') {
                            $nextNodes = $this->nextNodesFromGraph($graph, $node, $result['branch']);

                            if ($nextNodes->isNotEmpty()) {
                                $this->walkGraph($workflow, $graph, $nextNodes, $model, 'delayed_resume', $context, $run, $maxNodes, $hadFailure, $hadSuccess);
                            }
                        }
                    }

                    if ($hadFailure) {
                        throw new WorkflowRolledBackException(
                            "Workflow {$workflow->id} (delayed resume) rolled back due to action failure",
                        );
                    }
                });
            } catch (WorkflowRolledBackException $e) {
                Log::warning('filament-workflow: '.$e->getMessage());

                WorkflowExecutionLog::create([
                    'workflow_id' => $workflow->id,
                    'workflow_node_id' => null,
                    'model_type' => $model::class,
                    'model_id' => $model->getKey(),
                    'trigger_type' => 'delayed_resume',
                    'result' => 'failure',
                    'details' => ['reason' => 'rolled_back', 'message' => $e->getMessage(), 'run_id' => $run->id],
                    'executed_at' => now(),
                ]);

                $this->refreshAfterRollback($model);
            }
        } finally {
            unset(static::$executingModels[$modelKey]);
            $lock->release();
        }
    }

    /**
     * Whether a delayed continuation must stop instead of resuming.
     *
     * A run ends when it re-enters a node it already executed (a cycle that
     * passes through a delay node), when it has spent its node budget, when
     * it has been resumed more times than allowed, or when it has outlived
     * the maximum run duration. Each outcome is recorded so an operator can
     * see why the run stopped.
     */
    protected function runBudgetExhausted(Workflow $workflow, WorkflowNode $node, Model $model, WorkflowRun $run, int $maxNodes): bool
    {
        $reason = match (true) {
            $run->hasVisited($node->id) => 'Cycle detected: node already executed in this run',
            $run->executedCount() >= $maxNodes => 'Max node execution limit reached',
            $run->exceedsHopLimit((int) config('filament-workflow.max_run_hops', 100)) => 'Max delayed continuations reached for this run',
            $run->exceedsDuration((int) config('filament-workflow.max_run_days', 30)) => 'Max run duration reached',
            default => null,
        };

        if ($reason === null) {
            return false;
        }

        $this->logExecution($workflow, $node, $model, 'delayed_resume', [
            'result' => 'skipped',
            'branch' => null,
            'details' => ['reason' => $reason],
        ], $run);

        return true;
    }

    /**
     * Drop in-memory state written by a run the database has just rolled back.
     * Loaded relationships are dropped with it, since they may have been read
     * or mutated inside the aborted transaction.
     */
    protected function refreshAfterRollback(Model $model): void
    {
        if (! $model->exists) {
            return;
        }

        try {
            $model->refresh();
        } catch (\Throwable $e) {
            Log::warning('filament-workflow: could not refresh '.$model::class.':'.$model->getKey().' after rollback — '.$e->getMessage());
        }
    }

    /**
     * Log a node execution result. The run's identity is stamped onto every
     * row so an operator can follow one run across its delayed continuations.
     */
    protected function logExecution(
        Workflow $workflow,
        WorkflowNode $node,
        Model $model,
        string $triggerType,
        array $result,
        ?WorkflowRun $run = null,
    ): void {
        $details = $result['details'] ?? [];

        if ($run !== null) {
            $details['run_id'] = $run->id;
            $details['hop'] = $run->hop;
        }

        WorkflowExecutionLog::create([
            'workflow_id' => $workflow->id,
            'workflow_node_id' => $node->id,
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
            'trigger_type' => $triggerType,
            'result' => $result['result'],
            'details' => $details,
            'executed_at' => now(),
        ]);
    }
}
