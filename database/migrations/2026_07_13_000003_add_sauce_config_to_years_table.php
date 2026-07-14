<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuracion flexible de salsas por edicion (Fase 5B). Antes la regla
     * "1 salsa cada 2 porciones" estaba hardcodeada en
     * PricingService::calculateSauces(). Ahora cada Year define su propia
     * relacion "X salsas cada Y porciones" via estas dos columnas:
     *
     * - sauce_portions_per_block: el "Y" (cuantas porciones forman un bloque).
     * - sauce_units_per_block: el "X" (cuantas salsas da ese bloque completo).
     *
     * Default 2/1 = exactamente la regla anterior ("1 salsa cada 2
     * porciones"), para que las ediciones ya existentes (creadas antes de
     * esta migracion) sigan calculando salsas EXACTAMENTE igual que antes sin
     * necesidad de tocarlas a mano.
     *
     * Ver PricingService::calculateSauces() para la formula completa y la
     * documentacion de como se resolvio la ambiguedad de bloques parciales.
     */
    public function up(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->unsignedInteger('sauce_portions_per_block')->default(2)->after('made_portions');
            $table->unsignedInteger('sauce_units_per_block')->default(1)->after('sauce_portions_per_block');
        });
    }

    public function down(): void
    {
        Schema::table('years', function (Blueprint $table) {
            $table->dropColumn(['sauce_portions_per_block', 'sauce_units_per_block']);
        });
    }
};
