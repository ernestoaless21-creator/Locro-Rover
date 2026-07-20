<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reemplaza "Quitar de la edicion" (client_year_assignments->delete(), que
 * quedaba deshecho por el backfill automatico de ClientController::index en
 * la siguiente carga) por un estado global y no destructivo en el cliente:
 * is_active. Un cliente inactivo conserva TODO su historial (pedidos,
 * asignaciones de ediciones donde ya participo) intacto; is_active solo
 * gatea los mecanismos AUTOMATICOS de generacion hacia ediciones futuras
 * (ver ClientController::index backfill y
 * ClientAssignmentService::generateFromPreviousYear). No es un global scope
 * (ver Client::scopeActive): busquedas, importacion e historial siguen
 * viendo clientes inactivos sin excepcion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('historical_number');
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();
            $table->text('deactivation_reason')->nullable()->after('deactivated_by');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['is_active', 'deactivated_at', 'deactivation_reason']);
        });
    }
};
