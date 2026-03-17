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

class NodeExecutor
{
    public function __construct(
        protected ConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * Execute a node and return the result.
     *
     * @return array{result: string, branch: ?string, details: array}
     */
    public function execute(
        WorkflowNode $node,
        Model $model,
        array $context,
        array $triggers,
        array $actions,
    ): array {
        return match ($node->node_type) {
            NodeTypeEnum::TRIGGER => $this->executeTrigger($node, $model, $context, $triggers),
            NodeTypeEnum::CONDITION => $this->executeCondition($node, $model),
            NodeTypeEnum::DELAY => $this->executeDelay($node, $model, $context),
            NodeTypeEnum::ACTION => $this->executeAction($node, $model, $context, $actions),
        };
    }

    /**
     * Validate that the trigger node matches the event context.
     */
    protected function executeTrigger(WorkflowNode $node, Model $model, array $context, array $triggers): array
    {
        $triggerType = $node->type_config;
        $triggerClass = $triggers[$triggerType] ?? null;

        if (! $triggerClass) {
            return ['result' => 'skipped', 'branch' => null, 'details' => ['reason' => "Unknown trigger type: {$triggerType}"]];
        }

        /** @var TriggerInterface $trigger */
        $trigger = app($triggerClass);
        $matches = $trigger->matches($model, $node->config ?? [], $context);

        return [
            'result' => $matches ? 'success' : 'skipped',
            'branch' => null,
            'details' => ['trigger_type' => $triggerType, 'matched' => $matches],
        ];
    }

    /**
     * Evaluate condition node and determine which branch to follow.
     */
    protected function executeCondition(WorkflowNode $node, Model $model): array
    {
        $conditions = $node->getConfigValue('conditions', []);
        $logic = $node->getConfigValue('logic', 'and');

        $result = $this->conditionEvaluator->evaluate($model, $conditions, $logic);

        return [
            'result' => 'success',
            'branch' => $result ? 'Yes' : 'No',
            'details' => ['conditions_met' => $result, 'logic' => $logic],
        ];
    }

    /**
     * Schedule delayed execution for the next node.
     */
    protected function executeDelay(WorkflowNode $node, Model $model, array $context): array
    {
        $duration = (int) $node->getConfigValue('duration', 0);
        $unit = $node->getConfigValue('unit', 'minutes');

        $delaySeconds = match ($unit) {
            'seconds' => $duration,
            'hours' => $duration * 3600,
            'days' => $duration * 86400,
            default => $duration * 60, // minutes
        };

        // Dispatch a delayed job for the next nodes
        $nextNodes = $node->getNextNodes();
        foreach ($nextNodes as $nextNode) {
            ExecuteDelayedNodeJob::dispatch(
                $nextNode->id,
                $model::class,
                $model->getKey(),
                $context,
            )->delay(now()->addSeconds($delaySeconds))
                ->onQueue(config('filament-workflow.queue', 'automations'));
        }

        return [
            'result' => 'delayed',
            'branch' => null,
            'details' => ['delay' => $duration, 'unit' => $unit, 'scheduled_nodes' => $nextNodes->pluck('id')->toArray()],
        ];
    }

    /**
     * Execute an action node via the registered handler.
     */
    protected function executeAction(WorkflowNode $node, Model $model, array $context, array $actions): array
    {
        $actionType = $node->type_config;
        $actionClass = $actions[$actionType] ?? null;

        if (! $actionClass) {
            return ['result' => 'failure', 'branch' => null, 'details' => ['reason' => "Unknown action type: {$actionType}"]];
        }

        /** @var ActionHandlerInterface $handler */
        $handler = app($actionClass);

        try {
            $details = $handler->execute($model, $node->config ?? [], $context);

            return ['result' => 'success', 'branch' => null, 'details' => $details];
        } catch (\Throwable $e) {
            return ['result' => 'failure', 'branch' => null, 'details' => ['error' => $e->getMessage()]];
        }
    }
}
