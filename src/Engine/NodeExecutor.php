<?php

declare(strict_types=1);

/**
 * NodeExecutor
 *
 * Executes a single workflow node based on its type.
 * Delegates to the appropriate handler (trigger validation,
 * condition evaluation, delay scheduling, or action execution).
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Engine;

use Codenzia\FilamentWorkflow\Engine\Contracts\ActionHandlerInterface;
use Codenzia\FilamentWorkflow\Engine\Contracts\TriggerInterface;
use Codenzia\FilamentWorkflow\Enums\NodeTypeEnum;
use Codenzia\FilamentWorkflow\Jobs\ExecuteDelayedNodeJob;
use Codenzia\FilamentWorkflow\Models\WorkflowNode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class NodeExecutor
{
    public function __construct(
        protected ConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * Execute a node and return the result.
     *
     * @param  ?WorkflowRun  $run  Identity and budget of the run this node
     *                             belongs to; handed to delay nodes so their
     *                             continuation stays part of the same run.
     * @return array{result: string, branch: ?string, details: array}
     */
    public function execute(
        WorkflowNode $node,
        Model $model,
        array $context,
        array $triggers,
        array $actions,
        ?WorkflowRun $run = null,
    ): array {
        return match ($node->node_type) {
            NodeTypeEnum::TRIGGER => $this->executeTrigger($node, $model, $context, $triggers),
            NodeTypeEnum::CONDITION => $this->executeCondition($node, $model),
            NodeTypeEnum::DELAY => $this->executeDelay($node, $model, $context, $run),
            NodeTypeEnum::ACTION => $this->executeAction($node, $model, $context, $actions),
        };
    }

    /**
     * Validate that the trigger node matches the event context.
     *
     * A throw inside the trigger's matches() must not abort the whole
     * workflow run — the trigger is treated as non-matching and logged.
     */
    protected function executeTrigger(WorkflowNode $node, Model $model, array $context, array $triggers): array
    {
        $triggerType = $node->type_config;
        $triggerClass = $triggers[$triggerType] ?? null;

        if (! $triggerClass) {
            // Distinguish "registered trigger doesn't exist" from a normal
            // condition-mismatch skip: this is a configuration bug (likely a
            // service provider missing or the trigger key was renamed) and
            // the workflow is silently inert until it's fixed.
            //
            // We log loudly and surface a dedicated reason / unregistered_trigger
            // flag so the designer UI and log filters can spot it. We DO NOT
            // return 'failure' — that would roll back the run's transaction
            // including any sibling trigger nodes' successful actions, which
            // would be surprising for multi-trigger workflows.
            Log::warning("filament-workflow: workflow node {$node->id} references unregistered trigger '{$triggerType}' — this workflow won't fire until the trigger is registered");

            return [
                'result' => 'skipped',
                'branch' => null,
                'details' => [
                    'reason' => "Unregistered trigger type: '{$triggerType}'",
                    'trigger_type' => $triggerType,
                    'unregistered_trigger' => true,
                ],
            ];
        }

        try {
            /** @var TriggerInterface $trigger */
            $trigger = app($triggerClass);
            $matches = $trigger->matches($model, $node->config ?? [], $context);

            return [
                'result' => $matches ? 'success' : 'skipped',
                'branch' => null,
                'details' => ['trigger_type' => $triggerType, 'matched' => $matches],
            ];
        } catch (\Throwable $e) {
            Log::warning("filament-workflow: trigger '{$triggerType}' threw on node {$node->id} for ".$model::class.':'.$model->getKey().' — '.$e->getMessage());

            return [
                'result' => 'failure',
                'branch' => null,
                'details' => ['trigger_type' => $triggerType, 'error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Evaluate condition node and determine which branch to follow.
     *
     * A throw during evaluation must not abort the whole workflow run —
     * the condition is treated as not-met (branch: 'No') and logged.
     */
    protected function executeCondition(WorkflowNode $node, Model $model): array
    {
        $conditions = $node->getConfigValue('conditions', []);
        $logic = $node->getConfigValue('logic', 'and');

        try {
            $result = $this->conditionEvaluator->evaluate($model, $conditions, $logic);

            return [
                'result' => 'success',
                'branch' => $result ? 'Yes' : 'No',
                'details' => ['conditions_met' => $result, 'logic' => $logic],
            ];
        } catch (\Throwable $e) {
            Log::warning("filament-workflow: condition node {$node->id} threw for ".$model::class.':'.$model->getKey().' — '.$e->getMessage());

            // A condition is routing logic, not a side effect. Treat an
            // evaluation error as "condition not met" (follow the 'No'
            // branch) rather than a run failure — otherwise a misconfigured
            // downstream condition would roll back already-committed actions.
            return [
                'result' => 'skipped',
                'branch' => 'No',
                'details' => ['logic' => $logic, 'error' => $e->getMessage(), 'errored' => true],
            ];
        }
    }

    /**
     * Schedule delayed execution for the next node.
     *
     * The continuation carries the run's identity, hop count and visited
     * nodes, so the resumed work is still the same run: cycles through this
     * delay are detected and the run's budgets keep counting down. The
     * dispatch is deferred to after commit — a run that later rolls back
     * must not leave a scheduled continuation behind.
     */
    protected function executeDelay(WorkflowNode $node, Model $model, array $context, ?WorkflowRun $run = null): array
    {
        $duration = (int) $node->getConfigValue('duration', 0);
        $unit = $node->getConfigValue('unit', 'minutes');

        $delaySeconds = match ($unit) {
            'seconds' => $duration,
            'hours' => $duration * 3600,
            'days' => $duration * 86400,
            default => $duration * 60, // minutes
        };

        $continuation = ($run ?? WorkflowRun::start())->toContinuationArray();

        // Dispatch a delayed job for the next nodes
        $nextNodes = $node->getNextNodes();
        foreach ($nextNodes as $nextNode) {
            ExecuteDelayedNodeJob::dispatch(
                $nextNode->id,
                $model::class,
                $model->getKey(),
                $context,
                $continuation,
            )->delay(now()->addSeconds($delaySeconds))
                ->onQueue(config('filament-workflow.queue', 'automations'))
                ->afterCommit();
        }

        return [
            'result' => 'delayed',
            'branch' => null,
            'details' => [
                'delay' => $duration,
                'unit' => $unit,
                'scheduled_nodes' => $nextNodes->pluck('id')->toArray(),
                'next_hop' => $continuation['hop'],
            ],
        ];
    }

    /**
     * Execute an action node via the registered handler.
     *
     * The handler's own report of what it did is authoritative. An action
     * that reports a deliberate skip is logged as 'skipped', and one that
     * reports an error is logged as 'error' — neither is recorded as a
     * successful execution just because it returned without throwing.
     */
    protected function executeAction(WorkflowNode $node, Model $model, array $context, array $actions): array
    {
        $actionType = $node->type_config;
        $actionClass = $actions[$actionType] ?? null;

        if (! $actionClass) {
            Log::warning("filament-workflow: workflow node {$node->id} references unregistered action '{$actionType}' — register it via WorkflowEngine::registerAction()");

            return [
                'result' => 'failure',
                'branch' => null,
                'details' => [
                    'reason' => "Unregistered action type: '{$actionType}'",
                    'action_type' => $actionType,
                    'unregistered_action' => true,
                ],
            ];
        }

        /** @var ActionHandlerInterface $handler */
        $handler = app($actionClass);

        try {
            $details = $handler->execute($model, $node->config ?? [], $context);

            if (($details['skipped'] ?? false) === true) {
                return ['result' => 'skipped', 'branch' => null, 'details' => $details];
            }

            if (isset($details['error'])) {
                return ['result' => 'error', 'branch' => null, 'details' => $details];
            }

            return ['result' => 'success', 'branch' => null, 'details' => $details];
        } catch (\Throwable $e) {
            return ['result' => 'failure', 'branch' => null, 'details' => ['error' => $e->getMessage()]];
        }
    }
}
