<?php

declare(strict_types=1);

/**
 * TriggerInterface
 *
 * Implement this interface to create custom workflow triggers.
 * Triggers determine whether a workflow should start executing
 * based on the event context.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Engine\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TriggerInterface
{
    /**
     * The display label for this trigger type.
     */
    public static function label(): string;

    /**
     * Determine if this trigger matches the given context.
     *
     * @param  Model  $model    The model that fired the event
     * @param  array  $config   The trigger node's configuration (field, from, to, etc.)
     * @param  array  $context  Event context (e.g., ['from' => 'active', 'to' => 'closed'])
     */
    public function matches(Model $model, array $config, array $context): bool;
}
