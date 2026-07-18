<?php

namespace App\Services\Import\Adapters;

use App\Models\Client;
use App\Services\Import\ImportFormat;
use App\Services\Import\ImportNumberParser;
use App\Services\Import\ImportRow;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Formato propio de Locro Rover: permite reimportar un archivo exportado por
 * esta misma aplicacion (restauracion/migracion, ver Fase P2). v1, layout
 * definido junto con este importador y sujeto a ajuste cuando se construya
 * la funcion "Exportar Excel" (fuera de alcance de esta fase):
 *
 * ID Pedido | ID Cliente | Rover | Nombre | Apellido | Telefono | Direccion |
 * Codigo Postal | Delivery | Porciones | Salsas | Importe Total | Cobrado |
 * Medio de Pago | Observaciones | Estado | Retirado
 *
 * Diferencia clave con el formato Legacy: trae "ID Cliente" (historical_number),
 * que permite matchear el cliente de forma mas confiable que el telefono en
 * un escenario de restauracion total (ver ImportRow::$clientHistoricalNumber
 * y ClientMatcher).
 */
class LocroRoverExcelImportAdapter extends AbstractSingleSheetAdapter
{
    private const FINGERPRINT = ['id cliente', 'medio de pago'];

    public static function format(): ImportFormat
    {
        return ImportFormat::LocroRoverV1;
    }

    public function detect(Worksheet $sheet): bool
    {
        return $this->hasFingerprint($this->headerMap($this->readGrid($sheet)), self::FINGERPRINT);
    }

    /** @return ImportRow[] */
    public function parse(Worksheet $sheet): array
    {
        $grid = $this->readGrid($sheet);
        $map = $this->headerMap($grid);
        $rows = [];

        for ($i = 1; $i < count($grid); $i++) {
            $data = $grid[$i];
            $get = fn (string $header) => array_key_exists($header, $map) ? ($data[$map[$header]] ?? null) : null;

            $firstName = Client::normalizeName($this->str($get('nombre')));
            $lastName = Client::normalizeName($this->str($get('apellido')));
            $phone = $this->str($get('telefono'));
            $externalOrderId = $this->str($get('id pedido'));

            if ($firstName === null && $lastName === null && $phone === null && $externalOrderId === null) {
                continue;
            }

            $rows[] = new ImportRow(
                sourceRowNumber: $i + 1,
                externalOrderId: $externalOrderId,
                clientHistoricalNumber: ImportNumberParser::toNullableInt($get('id cliente')),
                roverName: $this->str($get('rover')),
                firstName: $firstName,
                lastName: $lastName,
                phoneRaw: $phone,
                address: $this->str($get('direccion')),
                postalCode: $this->str($get('codigo postal')),
                isDelivery: ImportNumberParser::toBool($get('delivery')),
                portions: ImportNumberParser::toNullableInt($get('porciones')),
                totalAmount: ImportNumberParser::toNullableFloat($get('importe total')),
                saucesQty: ImportNumberParser::toNullableInt($get('salsas')) ?? 0,
                observations: $this->str($get('observaciones')),
                amountCollected: ImportNumberParser::toNullableFloat($get('cobrado')),
                paymentMethodHint: $this->paymentMethodSlug($this->str($get('medio de pago'))),
                orderStatus: $this->orderStatus($this->str($get('estado'))),
                withdrawalStatus: ImportNumberParser::toBool($get('retirado')) ? 'retirado' : 'no_retirado',
            );
        }

        return $rows;
    }

    private function paymentMethodSlug(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strtolower(trim($value), 'UTF-8') === 'mercado pago' ? 'mercado_pago' : 'efectivo';
    }

    private function orderStatus(?string $value): ?string
    {
        $normalized = $value !== null ? mb_strtolower(trim($value), 'UTF-8') : null;

        return in_array($normalized, ['pendiente', 'confirmado', 'cancelado'], true) ? $normalized : null;
    }
}
