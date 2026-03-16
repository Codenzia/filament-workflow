<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_node_id')->constrained('workflow_nodes')->cascadeOnDelete();
            $table->foreignId('target_node_id')->constrained('workflow_nodes')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('workflow_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_connections');
    }
};
