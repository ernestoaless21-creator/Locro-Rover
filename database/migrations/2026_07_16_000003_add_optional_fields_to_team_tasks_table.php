<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 9 (ampliacion): agrega campos opcionales a team_tasks.
 * Todos son nullable para no romper las tareas ya existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_tasks', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->text('notes')->nullable()->after('description');
            $table->date('optimal_date')->nullable()->after('notes');
            $table->date('due_date')->nullable()->after('optimal_date');
        });
    }

    public function down(): void
    {
        Schema::table('team_tasks', function (Blueprint $table) {
            $table->dropColumn(['description', 'notes', 'optimal_date', 'due_date']);
        });
    }
};
