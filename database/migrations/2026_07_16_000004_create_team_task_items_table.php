<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 9 (ampliacion 2): subtareas opcionales por tarea de equipo.
 * La eliminación en cascada garantiza que al borrar una TeamTask
 * se borran todos sus items automáticamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_task_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_task_id')->constrained('team_tasks')->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_task_items');
    }
};
