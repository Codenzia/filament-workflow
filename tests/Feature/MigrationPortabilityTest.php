<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Guards portability: a host app may have no `projects` table. The workflows
 * migration must still succeed on sqlite/mysql/pgsql, creating `project_id`
 * as a plain nullable, indexed column with no enforced foreign key.
 */
it('creates the workflows table on a host with no projects table', function (): void {
    // Simulate a host application that does not own a `projects` table.
    Schema::dropIfExists('workflows');
    Schema::dropIfExists('projects');

    expect(Schema::hasTable('projects'))->toBeFalse();

    $migration = require __DIR__.'/../../database/migrations/2026_03_16_000001_create_workflows_table.php';
    $migration->up();

    expect(Schema::hasTable('workflows'))->toBeTrue()
        ->and(Schema::hasColumn('workflows', 'project_id'))->toBeTrue();

    // An arbitrary project_id must be insertable with no FK to violate.
    $id = \Codenzia\FilamentWorkflow\Models\Workflow::create([
        'name' => 'Portable',
        'model_type' => 'App\\Models\\Task',
        'project_id' => 999,
    ])->id;

    expect(\Codenzia\FilamentWorkflow\Models\Workflow::find($id)->project_id)->toBe(999);
});
