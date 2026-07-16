<?php

namespace App\Services;

use App\Models\PublicityMaterial;
use Illuminate\Support\Facades\DB;

/**
 * Importación de material publicitario entre ediciones (Fase 16).
 *
 * Mismo criterio aditivo que Compras/Infraestructura (Fases 14/15): no borra
 * lo que ya exista en el destino, salta duplicados.
 *
 * NUNCA duplica el archivo físico: el registro importado apunta al mismo
 * file_path del original (ver PublicityMaterialService::delete(), que
 * cuenta referencias antes de borrar del disco). Un material se considera
 * "duplicado" en el destino cuando ya existe un registro con el mismo
 * file_path — no hay un catálogo separado de materiales del cual copiar un
 * ID, cada registro por edición ES el material.
 */
class PublicityImportService
{
    /**
     * Lista los materiales del año origen para la pantalla de selección de
     * importación. file_path es un detalle interno de almacenamiento (el
     * modelo lo oculta de su serialización normal) y nunca viaja al
     * frontend, pero se usa aquí server-side para marcar qué materiales ya
     * están en el destino.
     */
    public function sourceMaterials(int $yearId, int $targetYearId): array
    {
        $targetFilePaths = $this->existingFilePaths($targetYearId);

        return PublicityMaterial::with('category')
            ->where('year_id', $yearId)
            ->get()
            ->sortBy(fn (PublicityMaterial $m) => $m->title)
            ->values()
            ->map(fn (PublicityMaterial $m) => [
                'id'             => $m->id,
                'category_name'  => $m->category->name,
                'title'          => $m->title,
                'file_name'      => $m->file_name,
                'file_size'      => $m->file_size,
                'already_exists' => in_array($m->file_path, $targetFilePaths, true),
            ])
            ->all();
    }

    public function existingFilePaths(int $yearId): array
    {
        return PublicityMaterial::where('year_id', $yearId)->pluck('file_path')->all();
    }

    /**
     * @param  int[]|null  $selectedMaterialIds  IDs de publicity_materials del año origen a importar. Null = todos.
     * @return array{imported: int, skipped_duplicates: int}
     */
    public function import(
        int $sourceYearId,
        int $targetYearId,
        int $createdBy,
        ?array $selectedMaterialIds = null,
    ): array {
        $result = ['imported' => 0, 'skipped_duplicates' => 0];

        DB::transaction(function () use ($sourceYearId, $targetYearId, $createdBy, $selectedMaterialIds, &$result) {
            $existingFilePaths = PublicityMaterial::where('year_id', $targetYearId)
                ->pluck('file_path')
                ->all();

            $sourceQuery = PublicityMaterial::where('year_id', $sourceYearId);
            if ($selectedMaterialIds !== null) {
                $sourceQuery->whereIn('id', $selectedMaterialIds);
            }

            foreach ($sourceQuery->get() as $source) {
                if (in_array($source->file_path, $existingFilePaths, true)) {
                    $result['skipped_duplicates']++;
                    continue;
                }

                PublicityMaterial::create([
                    'year_id'               => $targetYearId,
                    'publicity_category_id' => $source->publicity_category_id,
                    'title'                 => $source->title,
                    'description'           => $source->description,
                    'file_path'             => $source->file_path,
                    'file_name'             => $source->file_name,
                    'file_size'             => $source->file_size,
                    'mime_type'             => $source->mime_type,
                    'uploaded_by'           => $createdBy,
                    // NO copiado: notes, material_date (propios de la edición anterior).
                ]);

                $existingFilePaths[] = $source->file_path;
                $result['imported']++;
            }
        });

        return $result;
    }
}
