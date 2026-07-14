<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 7, seccion 3: "Crear una migracion segura que asigne numeros
 * historicos unicos a los clientes existentes sin perder datos."
 *
 * La columna `historical_number` ya existia (nullable/unique) desde la
 * migracion de Fase 6A, pero ningun proceso la completaba automaticamente
 * todavia para los clientes ya cargados. Esta migracion asigna, en orden
 * de alta (created_at, id como desempate estable), un numero historico
 * secuencial a cada cliente que TODAVIA no tenga uno, continuando a partir
 * del numero mas alto ya usado (nunca pisa un numero ya asignado a mano).
 *
 * SEGURA para una base con datos:
 * - Solo hace UPDATE de una columna, no borra ni recrea filas.
 * - Solo toca clientes con historical_number NULL.
 * - Es idempotente: si se corre de nuevo, no encuentra clientes sin numero
 *   y no hace nada.
 * - Incluye clientes soft-deleted (se recorren con withTrashed a nivel SQL,
 *   ya que esta migracion opera directo sobre la tabla): un cliente borrado
 *   logicamente conserva su lugar en el historial igual que uno activo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $next = (int) DB::table('clients')->max('historical_number') + 1;

            DB::table('clients')
                ->whereNull('historical_number')
                ->orderBy('created_at')
                ->orderBy('id')
                ->select('id')
                ->chunkById(200, function ($clients) use (&$next) {
                    foreach ($clients as $client) {
                        DB::table('clients')
                            ->where('id', $client->id)
                            ->update(['historical_number' => $next]);
                        $next++;
                    }
                });
        });
    }

    public function down(): void
    {
        // Deliberadamente no reversible: quitar numeros historicos ya
        // entregados y potencialmente comunicados a clientes/voluntarios
        // seria mas destructivo que dejar la migracion "down()" vacia.
        // Si hace falta revertir, se debe hacer a mano y con criterio.
    }
};
