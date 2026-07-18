<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Unico punto que sabe abrir un .xlsx (wrapea PhpSpreadsheet). Convierte
 * cualquier falla de lectura (archivo corrupto, no es realmente un xlsx,
 * etc.) en un ImportException con mensaje entendible, en vez de dejar
 * escapar la excepcion cruda de la libreria.
 */
class SpreadsheetReader
{
    public function load(string $absolutePath): Worksheet
    {
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($absolutePath);
        } catch (Throwable) {
            throw ImportException::unreadableFile();
        }

        return $spreadsheet->getActiveSheet();
    }
}
