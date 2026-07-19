<?php

use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correccion de datos (Fase P2): "Mercado Pago" dejo de existir como medio de
 * pago independiente en el modelo (ver ImportService::persist() y los
 * adapters de import, que desde ahora normalizan cualquier valor equivalente
 * a "Mercado Pago" como 'transferencia' directamente durante la
 * importacion). Antes de este cambio, el Excel historico podia dejar pagos
 * registrados con el medio 'mercado_pago': el Dashboard ya los sumaba junto
 * con el resto de "no efectivo" (ver DashboardController/OrderController,
 * que agrupan por cualquier slug distinto de 'efectivo'), pero el filtro
 * "Transferencia" de Pedidos los dejaba afuera porque comparaba el slug
 * exacto ('transferencia').
 *
 * Esta migracion reasigna (UPDATE, no borra) cada Payment que apuntaba a
 * 'mercado_pago' para que apunte a 'transferencia' -- los importes, pedidos
 * y estadisticas quedan exactamente iguales, solo cambia a que medio de pago
 * esta asociado cada pago -- y despues elimina el medio de pago
 * 'mercado_pago', que ya no debe quedar disponible como opcion independiente.
 *
 * Incluye pagos soft-deleted (se opera con el query builder crudo sobre la
 * tabla, no con el modelo Eloquent, para no filtrarlos por el scope global
 * de SoftDeletes): la referencia debe desaparecer por completo antes de
 * poder borrar la fila de payment_methods (restrictOnDelete).
 */
return new class extends Migration
{
    public function up(): void
    {
        $mercadoPago = PaymentMethod::where('slug', 'mercado_pago')->first();

        if ($mercadoPago === null) {
            return;
        }

        $transferencia = PaymentMethod::firstOrCreate(
            ['slug' => 'transferencia'],
            ['name' => 'Transferencia', 'is_active' => true]
        );

        DB::table('payments')
            ->where('payment_method_id', $mercadoPago->id)
            ->update(['payment_method_id' => $transferencia->id]);

        $mercadoPago->delete();
    }

    public function down(): void
    {
        // Normalizacion intencional e irreversible: "Mercado Pago" no debe
        // volver a existir como medio de pago independiente, y no hay forma
        // de distinguir despues de up() que pagos de 'transferencia' eran
        // originalmente 'mercado_pago' para revertir el repointing.
    }
};
