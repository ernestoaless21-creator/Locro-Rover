<?php

use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;

/**
 * Fase P2: el formato Legacy trae "Mercado pago SI/NO" por fila (ver
 * LegacyExcelImportAdapter); se necesita el medio de pago 'Mercado Pago'
 * disponible para poder registrar esos pagos importados. Mismo patron que ya
 * usa RolesAndPermissionsSeeder para Efectivo/Transferencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        PaymentMethod::firstOrCreate(
            ['slug' => 'mercado_pago'],
            ['name' => 'Mercado Pago', 'is_active' => true],
        );
    }

    public function down(): void
    {
        // No se elimina: podria haber pagos reales ya registrados con este medio.
    }
};
