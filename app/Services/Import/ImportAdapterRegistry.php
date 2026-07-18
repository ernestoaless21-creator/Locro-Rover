<?php

namespace App\Services\Import;

use App\Services\Import\Contracts\ImportFormatAdapter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Punto unico de deteccion/resolucion de adapters. La lista de adapters
 * disponibles se inyecta (ver AppServiceProvider, binding por tag): agregar
 * un formato nuevo no requiere tocar esta clase.
 */
class ImportAdapterRegistry
{
    /** @param  ImportFormatAdapter[]  $adapters */
    public function __construct(private readonly array $adapters) {}

    /** @return ImportFormatAdapter[] adapters cuyo detect() matcheo (0, 1 o N) */
    public function detectFormat(Worksheet $sheet): array
    {
        return array_values(array_filter(
            $this->adapters,
            fn (ImportFormatAdapter $adapter) => $adapter->detect($sheet),
        ));
    }

    public function get(ImportFormat $format): ImportFormatAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter::format() === $format) {
                return $adapter;
            }
        }

        throw ImportException::unsupportedFormat();
    }

    /** @return ImportFormatAdapter[] */
    public function all(): array
    {
        return $this->adapters;
    }
}
