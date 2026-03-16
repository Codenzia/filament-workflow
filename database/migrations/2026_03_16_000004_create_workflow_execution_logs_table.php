<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_execution_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_node_id')->nullable()->constrained('workflow_nodes')->nullOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('trigger_type');
            $table->string('result');
            $table->json('details')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['workflow_id', 'executed_at']);
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_execution_logs');
    }
};
