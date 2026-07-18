<?php

namespace App\Services\Import;

/**
 * Formatos de archivo soportados por el importador historico. Cerrado a
 * proposito (enum, no string libre): agregar un formato nuevo implica crear
 * su adapter + un caso aca + una linea de registro en AppServiceProvider, sin
 * tocar el resto del pipeline (ver ImportService).
 */
enum ImportFormat: string
{
    case LegacySite = 'legacy_site';
    case LocroRoverV1 = 'locro_rover_v1';

    public function label(): string
    {
        return match ($this) {
            self::LegacySite => 'Página anterior (formato histórico)',
            self::LocroRoverV1 => 'Locro Rover (exportación propia)',
        };
    }
}
