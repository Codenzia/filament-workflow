<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('model_type');
            // Optional link to a host-app "project" concept. This package does
            // not own or ship a `projects` table, so the column is left as a
            // plain nullable, indexed foreign id with no enforced constraint —
            // keeping migrations portable across sqlite/mysql/pgsql on hosts
            // that have no `projects` table. The FK is attached below only when
            // the host application actually provides that table.
            $table->foreignId('project_id')->nullable();
            $table->string('status')->default('draft');
            $table->integer('priority')->default(0);
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->json('canvas_data')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['model_type', 'status']);
            $table->index(['project_id', 'status']);
        });

        // Attach the project foreign key only when the host application owns a
        // `projects` table. Kept outside the create() closure so the workflows
        // table is created regardless of the host schema.
        if (Schema::hasTable('projects')) {
            Schema::table('workflows', function (Blueprint $table): void {
                $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
