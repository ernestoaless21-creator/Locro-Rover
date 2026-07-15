<?php

namespace App\Services;

use App\Models\InfrastructureInventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Importación de inventario de infraestructura entre ediciones (Fase 15).
 *
 * Mismo criterio aditivo que la importación de compras (Fase 14): no borra
 * lo que ya exista en el destino, salta elementos duplicados.
 *
 * NUNCA importa préstamos: pertenecen a su propia edición y no deben
 * reaparecer como activos en una edición nueva por accidente.
 *
 * NO copia `own_to_repair_quantity` por defecto (sección 14 del prompt): no
 * hay forma segura de asumir que lo que necesitaba reparación el año
 * anterior sigue en el mismo estado, o que ya fue reparado.
 */
class InfrastructureImportService
{
    public function sourceItems(int $yearId): array
    {
        return InfrastructureInventoryItem::with('item')
            ->where('year_id', $yearId)
            ->get()
            ->sortBy(fn (InfrastructureInventoryItem $inv) => $inv->item->name)
            ->values()
            ->map(fn (InfrastructureInventoryItem $inv) => [
                'id'                      => $inv->id,
                'infrastructure_item_id'  => $inv->infrastructure_item_id,
                'item_name'               => $inv->item->name,
                'needed_quantity'         => $inv->needed_quantity,
                'own_available_quantity'  => $inv->own_available_quantity,
                'own_to_repair_quantity'  => $inv->own_to_repair_quantity,
            ])
            ->all();
    }

    public function existingItemIds(int $yearId): array
    {
        return InfrastructureInventoryItem::where('year_id', $yearId)->pluck('infrastructure_item_id')->all();
    }

    /**
     * @param  int[]|null  $selectedItemIds  IDs de infrastructure_items del año origen a importar. Null = todos.
     * @return array{imported: int, skipped_duplicates: int}
     */
    public function import(
        int $sourceYearId,
        int $targetYearId,
        int $createdBy,
        ?array $selectedItemIds = null,
    ): array {
        $result = ['imported' => 0, 'skipped_duplicates' => 0];

        DB::transaction(function () use ($sourceYearId, $targetYearId, $createdBy, $selectedItemIds, &$result) {
            $existingItemIds = InfrastructureInventoryItem::where('year_id', $targetYearId)
                ->pluck('infrastructure_item_id')
                ->all();

            $sourceQuery = InfrastructureInventoryItem::where('year_id', $sourceYearId);
            if ($selectedItemIds !== null) {
                $sourceQuery->whereIn('infrastructure_item_id', $selectedItemIds);
            }

            foreach ($sourceQuery->get() as $sourceInv) {
                if (in_array($sourceInv->infrastructure_item_id, $existingItemIds, true)) {
                    $result['skipped_duplicates']++;
                    continue;
                }

                InfrastructureInventoryItem::create([
                    'year_id'                 => $targetYearId,
                    'infrastructure_item_id'  => $sourceInv->infrastructure_item_id,
                    'needed_quantity'         => $sourceInv->needed_quantity,
                    'own_available_quantity'  => $sourceInv->own_available_quantity,
                    'own_to_repair_quantity'  => 0,
                    'created_by'              => $createdBy,
                    // NO copiado: own_to_repair_quantity (revisar caso a caso),
                    // notes (observaciones de la edición anterior).
                ]);

                $existingItemIds[] = $sourceInv->infrastructure_item_id;
                $result['imported']++;
            }
        });

        return $result;
    }
}
