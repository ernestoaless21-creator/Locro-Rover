<?php

namespace App\Services\Import\Adapters;

use App\Services\Import\Contracts\ImportFormatAdapter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Helpers comunes a los adapters de "una sola hoja, una fila de encabezados
 * en la fila 1, mapeo por nombre de columna" (hoy Legacy y Locro Rover; si
 * un formato futuro necesita otra cosa, simplemente no extiende esta base).
 * No es en si un ImportFormatAdapter: cada subclase implementa
 * format()/detect()/parse().
 */
abstract class AbstractSingleSheetAdapter implements ImportFormatAdapter
{
    /**
     * @return array<int, array<int, mixed>> grilla 0-indexada (fila 0 = encabezados)
     */
    protected function readGrid(Worksheet $sheet): array
    {
        // formatData=false: se piden los valores crudos (numeros como
        // numero, no como texto formateado con miles/moneda), para que
        // ImportNumberParser reciba datos consistentes sin importar el
        // formato visual que tenia la celda en Excel.
        return $sheet->toArray(null, true, false, false, false);
    }

    /** @return array<string,int> encabezado normalizado (lowercase, trim) => indice de columna 0-based */
    protected function headerMap(array $grid): array
    {
        $map = [];

        foreach ($grid[0] ?? [] as $idx => $value) {
            $normalized = $this->normalizeHeader($value);
            if ($normalized !== null) {
                $map[$normalized] = $idx;
            }
        }

        return $map;
    }

    /** @param  string[]  $requiredHeaders */
    protected function hasFingerprint(array $headerMap, array $requiredHeaders): bool
    {
        foreach ($requiredHeaders as $needle) {
            if (! array_key_exists($needle, $headerMap)) {
                return false;
            }
        }

        return true;
    }

    protected function normalizeHeader(mixed $value): ?string
    {
        $str = $this->str($value);

        return $str !== null ? mb_strtolower($str, 'UTF-8') : null;
    }

    protected function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);

        return $str === '' ? null : $str;
    }
}
