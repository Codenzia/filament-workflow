<?php

declare(strict_types=1);

/**
 * TimeTriggerInterface
 *
 * Implement this (in addition to TriggerInterface) on triggers that are
 * evaluated by the scheduled `workflow:process-time-triggers` command and
 * the designer's time-trigger preview. The engine resolves an INSTANCE of
 * the trigger and calls matchingModels() — unlike an Eloquent local scope,
 * this method is never called statically, so a conventional non-static
 * implementation no longer raises a PHP 8 "cannot be called statically"
 * fatal.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow\Engine\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface TimeTriggerInterface
{
    /**
     * Constrain the given query to the models that currently match this
     * time-based trigger (e.g., records whose deadline has passed).
     *
     * @param  Builder  $query  A fresh query for the workflow's model type
     * @param  array  $config  The trigger node's configuration
     */
    public function matchingModels(Builder $query, array $config): Builder;
}
