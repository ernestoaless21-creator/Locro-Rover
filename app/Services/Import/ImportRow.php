<?php

namespace App\Services\Import;

/**
 * Modelo de datos comun que devuelve CUALQUIER adapter (ver
 * Contracts\ImportFormatAdapter). El resto del pipeline (validacion,
 * transformacion, importacion) solo conoce esta clase, nunca las columnas
 * originales del Excel: agregar un formato nuevo no requiere tocar nada mas
 * que el adapter correspondiente.
 *
 * Los valores vienen "crudos" (recien extraidos + tipados), sin validar
 * reglas de negocio todavia (eso es responsabilidad de RowValidator) y sin
 * resolver relaciones (cliente/rover/medio de pago, responsabilidad de
 * ClientMatcher/RoverMatcher/ImportService).
 */
final class ImportRow
{
    public function __construct(
        public readonly int $sourceRowNumber,
        public readonly ?string $externalOrderId,
        public readonly ?int $clientHistoricalNumber,
        public readonly ?string $roverName,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $phoneRaw,
        public readonly ?string $address,
        public readonly ?string $postalCode,
        public readonly bool $isDelivery,
        public readonly ?int $portions,
        public readonly ?float $totalAmount,
        public readonly ?int $saucesQty,
        public readonly ?string $observations,
        public readonly ?float $amountCollected,
        public readonly ?string $paymentMethodHint,
        public readonly ?string $orderStatus,
        public readonly ?string $withdrawalStatus,
    ) {}
}
