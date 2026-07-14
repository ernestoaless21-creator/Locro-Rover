<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6A. Metas de venta configurables por edicion (Dashboard):
 * - sales_goal_global: meta global de porciones para la edicion (ej. 1500).
 * - sales_goal_individual_default: meta individual orientativa por Rover
 *   (ej. 70). Es un DEFAULT por edicion, no por usuario: no se pide en este
 *   prompt una meta distinta por persona.
 * Ambas nullable: si no estan configuradas, el Dashboard no debe dividir por
 * cero ni mostrar un porcentaje invalido (ver DashboardController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->unsignedInteger('sales_goal_global')->nullable()->after('made_portions');
            $table->unsignedInteger('sales_goal_individual_default')->nullable()->after('sales_goal_global');
        });
    }

    public function down(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->dropColumn(['sales_goal_global', 'sales_goal_individual_default']);
        });
    }
};
