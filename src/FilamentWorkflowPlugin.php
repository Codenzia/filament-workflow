<?php

declare(strict_types=1);

/**
 * FilamentWorkflowPlugin
 *
 * Filament v4 panel plugin for the workflow automation engine.
 *
 * @author Codenzia
 */

namespace Codenzia\FilamentWorkflow;

use Codenzia\FilamentWorkflow\Pages\WorkflowDesigner;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Livewire\Livewire;

class FilamentWorkflowPlugin implements Plugin
{
    public static function make(): static
    {
        return new static;
    }

    public function getId(): string
    {
        return 'filament-workflow';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        Livewire::component('codenzia.filament-workflow.pages.workflow-designer', WorkflowDesigner::class);
    }
}
