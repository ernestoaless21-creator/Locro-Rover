<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 7, seccion 13: se detecto que la exportacion (y ahora tambien la
 * pantalla fusionada de Clientes) puede omitir un pedido real cuyo cliente
 * no tiene una fila en `client_year_assignments` para esa edicion.
 *
 * Desde que existe ClientAssignmentService::syncFromOrder (Fase 6A), todo
 * ALTA o EDICION de pedido hecha a traves de OrderController crea o
 * reutiliza esa asignacion automaticamente. Pero pedidos cargados ANTES de
 * que esa sincronizacion existiera (o por cualquier otra via que no haya
 * pasado por OrderController) pueden haber quedado sin su asignacion
 * correspondiente. Esta migracion es un backfill de una sola vez: crea la
 * fila de asignacion faltante para cada combinacion (client_id, year_id)
 * que aparece en `orders` pero no en `client_year_assignments`.
 *
 * SEGURA para una base con datos:
 * - Solo INSERTA filas que faltan (usa NOT EXISTS), nunca actualiza ni
 *   borra una asignacion ya existente.
 * - No modifica pedidos, pagos ni ningun otro dato.
 * - Es idempotente: si se corre de nuevo, no encuentra faltantes.
 * - El responsable asignado a la asignacion nueva es el rover_id del pedido
 *   MAS RECIENTE de ese cliente/edicion (mismo criterio de "ultimo pedido
 *   gana" que ya usa syncFromOrder al ir actualizando la asignacion pedido
 *   a pedido); si ningun pedido tiene rover, queda sin asignar (igual que
 *   el comportamiento normal de una asignacion nueva).
 * - El estado de contacto se marca 'pedido_realizado' (coherente con lo que
 *   syncFromOrder hace siempre que existe un pedido real para esa asignacion).
 */
return new class extends Migration
{
    public function up(): void
    {
        $pairs = DB::table('orders')
            ->select('client_id', 'year_id')
            ->whereNull('deleted_at')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $exists = DB::table('client_year_assignments')
                ->where('client_id', $pair->client_id)
                ->where('year_id', $pair->year_id)
                ->exists();

            if ($exists) {
                continue;
            }

            $lastOrder = DB::table('orders')
                ->where('client_id', $pair->client_id)
                ->where('year_id', $pair->year_id)
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['rover_id', 'created_by']);

            DB::table('client_year_assignments')->insert([
                'client_id' => $pair->client_id,
                'year_id' => $pair->year_id,
                'assigned_user_id' => $lastOrder->rover_id ?? null,
                'contact_status' => 'pedido_realizado',
                'created_by' => $lastOrder->created_by ?? null,
                'updated_by' => $lastOrder->created_by ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No reversible: no hay forma segura de distinguir, despues del
        // hecho, las asignaciones creadas por este backfill de las creadas
        // normalmente por el uso real de la aplicacion.
    }
};
