<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('node_type');
            $table->string('type_config')->nullable();
            $table->json('config')->nullable();
            $table->float('position_x')->default(0);
            $table->float('position_y')->default(0);
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'node_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_nodes');
    }
};
