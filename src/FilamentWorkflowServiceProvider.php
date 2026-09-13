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
use Codenzia\FilamentWorkflow\Pages\WorkflowDesigner;
use Codenzia\FilamentWorkflow\Triggers\FieldChangedTrigger;
use Codenzia\FilamentWorkflow\Triggers\ModelCreatedTrigger;
use Codenzia\FilamentWorkflow\Triggers\ModelUpdatedTrigger;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-workflow');

        // Register the shipped triggers so the package works without host glue.
        // Registration is keyed and therefore idempotent — a host re-registering
        // the same key simply overrides these defaults.
        WorkflowEngine::registerTrigger('model.created', ModelCreatedTrigger::class);
        WorkflowEngine::registerTrigger('model.updated', ModelUpdatedTrigger::class);
        WorkflowEngine::registerTrigger('field.changed', FieldChangedTrigger::class);

        Livewire::component('codenzia.filament-workflow.pages.workflow-designer', WorkflowDesigner::class);

        FilamentAsset::register([
            Css::make('filament-workflow', __DIR__.'/../resources/assets/css/filament-workflow.css'),
        ], package: 'codenzia/filament-workflow');

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
