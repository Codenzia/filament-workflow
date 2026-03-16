<?php

declare(strict_types=1);

/**
 * FilamentWorkflowServiceProvider
 *
 * Registers the workflow engine, migrations, config, and views.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow;

use Codenzia\FilamentWorkflow\Commands\ProcessTimeTriggersCommand;
use Codenzia\FilamentWorkflow\Engine\WorkflowEngine;
use Illuminate\Support\ServiceProvider;

class FilamentWorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-workflow.php', 'filament-workflow');

        $this->app->singleton(WorkflowEngine::class, function ($app): WorkflowEngine {
            return new WorkflowEngine;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-workflow');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filament-workflow.php' => config_path('filament-workflow.php'),
            ], 'filament-workflow-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'filament-workflow-migrations');

            $this->commands([
                ProcessTimeTriggersCommand::class,
            ]);
        }
    }
}
