<?php

declare(strict_types=1);

/**
 * ActionHandlerInterface
 *
 * Implement this interface to create custom workflow actions.
 * Actions perform operations on models when a workflow reaches
 * an action node.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Engine\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ActionHandlerInterface
{
    /**
     * The display label for this action type.
     */
    public static function label(): string;

    /**
     * Execute the action on the given model.
     *
     * @param  Model  $model    The model to act upon
     * @param  array  $config   The action node's configuration (field, value, user_id, etc.)
     * @param  array  $context  Original trigger context
     * @return array  Execution details for logging (what changed, etc.)
     */
    public function execute(Model $model, array $config, array $context): array;
}
