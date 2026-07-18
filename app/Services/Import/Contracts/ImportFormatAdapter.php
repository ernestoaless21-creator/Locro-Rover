<?php

namespace App\Services\Import\Contracts;

use App\Services\Import\ImportFormat;
use App\Services\Import\ImportRow;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Contrato que debe implementar cada formato de archivo soportado. Un
 * adapter SOLO sabe interpretar sus propias columnas; nunca valida reglas de
 * negocio (RowValidator) ni toca la base (ClientMatcher/RoverMatcher/
 * ImportService). Agregar un formato nuevo = una clase nueva que implemente
 * esto + registrarla en AppServiceProvider.
 */
interface ImportFormatAdapter
{
    public static function format(): ImportFormat;

    /**
     * Fingerprint por nombres de columna presentes en la fila de encabezados
     * (nunca por posicion: reordenar columnas no debe romper la deteccion).
     */
    public function detect(Worksheet $sheet): bool;

    /** @return ImportRow[] */
    public function parse(Worksheet $sheet): array;
}
