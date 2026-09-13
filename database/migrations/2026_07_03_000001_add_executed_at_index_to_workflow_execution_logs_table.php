<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_execution_logs', function (Blueprint $table): void {
            $table->index('executed_at');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_execution_logs', function (Blueprint $table): void {
            $table->dropIndex(['executed_at']);
        });
    }
};
