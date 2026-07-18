<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Unica excepcion del pipeline de importacion, con constructores nombrados
 * para cada situacion (en vez de una jerarquia de clases por caso: no hay
 * necesidad de catch selectivo por tipo, el Controller distingue por
 * ->context()).
 */
class ImportException extends RuntimeException
{
    /**
     * @param  ImportFormat[]  $candidates  solo poblado para ambiguousFormat()
     */
    public function __construct(string $message, private readonly array $candidates = [])
    {
        parent::__construct($message);
    }

    public static function unreadableFile(): self
    {
        return new self('No se pudo leer el archivo. Verificá que sea un .xlsx válido y no esté corrupto.');
    }

    public static function unsupportedFormat(): self
    {
        return new self('No se reconoce el formato de este archivo. Verificá que las columnas coincidan con alguno de los formatos soportados.');
    }

    /** @param  ImportFormat[]  $candidates */
    public static function ambiguousFormat(array $candidates): self
    {
        return new self('El archivo coincide con más de un formato posible. Elegí el formato manualmente.', $candidates);
    }

    public static function blockedByErrors(): self
    {
        return new self('El archivo tiene errores que impiden la importación. Revisá la vista previa.');
    }

    /** @return ImportFormat[] */
    public function candidates(): array
    {
        return $this->candidates;
    }
}
