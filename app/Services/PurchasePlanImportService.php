<?php

namespace App\Services;

use App\Models\PurchasePlanItem;
use Illuminate\Support\Facades\DB;

/**
 * Importación de planificación de compras entre ediciones (Fase 14).
 *
 * A diferencia de la importación de cronograma (Fase 13), que reemplaza por
 * completo el destino, esta importación es ADITIVA: no borra lo que ya
 * exista en la edición destino. Cada producto que ya tenga un ítem en el
 * destino se omite (se reporta como "ya existía"), evitando duplicados sin
 * descartar carga manual previa.
 */
class PurchasePlanImportService
{
    public function sourceItems(int $yearId): array
    {
        return PurchasePlanItem::with(['product.category'])
            ->where('year_id', $yearId)
            ->get()
            ->sortBy(fn (PurchasePlanItem $item) => $item->product->name)
            ->values()
            ->map(fn (PurchasePlanItem $item) => [
                'id'            => $item->id,
                'product_id'    => $item->purchase_product_id,
                'product_name'  => $item->product->name,
                'category_name' => $item->product->category?->name,
                'unit'          => $item->unit,
                'qty_1000'      => $item->qty_1000,
                'qty_1500'      => $item->qty_1500,
            ])
            ->all();
    }

    public function existingProductIds(int $yearId): array
    {
        return PurchasePlanItem::where('year_id', $yearId)->pluck('purchase_product_id')->all();
    }

    /**
     * @param  int[]|null  $selectedProductIds  IDs de purchase_products del año origen a importar. Null = todos.
     * @return array{imported: int, skipped_duplicates: int}
     */
    public function import(
        int $sourceYearId,
        int $targetYearId,
        int $createdBy,
        ?array $selectedProductIds = null,
    ): array {
        $result = ['imported' => 0, 'skipped_duplicates' => 0];

        DB::transaction(function () use ($sourceYearId, $targetYearId, $createdBy, $selectedProductIds, &$result) {
            $existingProductIds = PurchasePlanItem::where('year_id', $targetYearId)
                ->pluck('purchase_product_id')
                ->all();

            $sourceQuery = PurchasePlanItem::where('year_id', $sourceYearId);
            if ($selectedProductIds !== null) {
                $sourceQuery->whereIn('purchase_product_id', $selectedProductIds);
            }

            foreach ($sourceQuery->get() as $sourceItem) {
                if (in_array($sourceItem->purchase_product_id, $existingProductIds, true)) {
                    $result['skipped_duplicates']++;
                    continue;
                }

                PurchasePlanItem::create([
                    'year_id'              => $targetYearId,
                    'purchase_product_id'  => $sourceItem->purchase_product_id,
                    'qty_1000'             => $sourceItem->qty_1000,
                    'qty_1500'             => $sourceItem->qty_1500,
                    'unit'                 => $sourceItem->unit,
                    'estimated_total_price' => $sourceItem->estimated_total_price,
                    'planned_supplier_id'  => $sourceItem->planned_supplier_id,
                    'created_by'           => $createdBy,
                    // NO copiado (ejecución real): actual_quantity, actual_total_price,
                    // actual_supplier_id, notes (pueden contener comentarios de ejecución
                    // de la edición anterior que no aplican a la nueva).
                ]);

                $existingProductIds[] = $sourceItem->purchase_product_id;
                $result['imported']++;
            }
        });

        return $result;
    }
}
