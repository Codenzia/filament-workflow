<?php

declare(strict_types=1);

namespace Codenzia\FilamentWorkflow\Tests;

use Codenzia\FilamentWorkflow\FilamentWorkflowServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            FilamentWorkflowServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        // Enable foreign key constraints for SQLite
        if ($this->app['db']->connection()->getDriverName() === 'sqlite') {
            $this->app['db']->connection()->statement('PRAGMA foreign_keys = ON');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Create a basic users table for FK constraints
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        // Create a basic projects table for FK constraints
        $this->app['db']->connection()->getSchemaBuilder()->create('projects', function ($table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Create test_models table for fixture
        $this->app['db']->connection()->getSchemaBuilder()->create('test_models', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('status')->default('active');
            $table->string('priority')->default('medium');
            $table->integer('progress')->default(0);
            $table->integer('project_id')->nullable();
            $table->timestamps();
        });
    }
}
