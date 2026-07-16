<?php

namespace App\Services;

use App\Models\LogisticsRecord;
use Illuminate\Support\Facades\DB;

/**
 * Importación de registros de logística entre ediciones (Fase 17).
 *
 * Mismo criterio aditivo que Publicidad/Compras/Infraestructura: no borra
 * lo que ya exista en el destino, salta duplicados.
 *
 * NUNCA duplica el archivo físico: el registro importado apunta al mismo
 * file_path del original (ver LogisticsRecordService::delete()/update(),
 * que cuentan referencias antes de borrar del disco). Un registro se
 * considera "duplicado" en el destino cuando ya existe uno con el mismo
 * file_path — no hay un catálogo separado del cual copiar un ID, cada
 * registro por edición ES el dato.
 */
class LogisticsImportService
{
    /**
     * Lista los registros del año origen para la pantalla de selección de
     * importación. file_path es un detalle interno de almacenamiento (el
     * modelo lo oculta de su serialización normal) y nunca viaja al
     * frontend, pero se usa aquí server-side para marcar qué registros ya
     * están en el destino.
     */
    public function sourceRecords(int $yearId, int $targetYearId): array
    {
        $targetFilePaths = $this->existingFilePaths($targetYearId);

        return LogisticsRecord::with('category')
            ->where('year_id', $yearId)
            ->get()
            ->sortBy(fn (LogisticsRecord $r) => $r->title)
            ->values()
            ->map(fn (LogisticsRecord $r) => [
                'id'             => $r->id,
                'category_name'  => $r->category->name,
                'title'          => $r->title,
                'file_name'      => $r->file_name,
                'file_size'      => $r->file_size,
                'already_exists' => in_array($r->file_path, $targetFilePaths, true),
            ])
            ->all();
    }

    public function existingFilePaths(int $yearId): array
    {
        return LogisticsRecord::where('year_id', $yearId)->pluck('file_path')->all();
    }

    /**
     * @param  int[]|null  $selectedRecordIds  IDs de logistics_records del año origen a importar. Null = todos.
     * @return array{imported: int, skipped_duplicates: int}
     */
    public function import(
        int $sourceYearId,
        int $targetYearId,
        int $createdBy,
        ?array $selectedRecordIds = null,
    ): array {
        $result = ['imported' => 0, 'skipped_duplicates' => 0];

        DB::transaction(function () use ($sourceYearId, $targetYearId, $createdBy, $selectedRecordIds, &$result) {
            $existingFilePaths = LogisticsRecord::where('year_id', $targetYearId)
                ->pluck('file_path')
                ->all();

            $sourceQuery = LogisticsRecord::where('year_id', $sourceYearId);
            if ($selectedRecordIds !== null) {
                $sourceQuery->whereIn('id', $selectedRecordIds);
            }

            foreach ($sourceQuery->get() as $source) {
                if (in_array($source->file_path, $existingFilePaths, true)) {
                    $result['skipped_duplicates']++;
                    continue;
                }

                LogisticsRecord::create([
                    'year_id'                => $targetYearId,
                    'logistics_category_id'  => $source->logistics_category_id,
                    'title'                  => $source->title,
                    'description'            => $source->description,
                    // purpose describe el archivo en sí (para qué sirve), no un hecho
                    // puntual de esa edición, así que se copia igual que description.
                    'purpose'                => $source->purpose,
                    'file_path'              => $source->file_path,
                    'file_name'              => $source->file_name,
                    'file_size'              => $source->file_size,
                    'mime_type'              => $source->mime_type,
                    'uploaded_by'            => $createdBy,
                    // NO copiado: notes, record_date (propios de la edición anterior).
                ]);

                $existingFilePaths[] = $source->file_path;
                $result['imported']++;
            }
        });

        return $result;
    }
}
