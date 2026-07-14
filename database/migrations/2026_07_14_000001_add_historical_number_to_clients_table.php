<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6A. Numero historico permanente del cliente (ej. "Cliente #184"),
 * independiente del anio y de los pedidos. Ver Client::historical_number.
 *
 * - nullable: no rompe clientes existentes ni obliga a inventar un numero.
 * - unique cuando tiene valor: Laravel/MySQL/SQLite permiten multiples NULL
 *   en una columna unique, solo se exige unicidad entre los valores no nulos.
 * - Solo logistica/jefe_logistica/admin pueden gestionarlo (ver ClientPolicy
 *   y ClientController::updateHistoricalNumber); los demas solo lo ven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('historical_number')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('historical_number');
        });
    }
};
