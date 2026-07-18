<?php

namespace App\Services\Import\Adapters;

use App\Models\Client;
use App\Services\Import\ImportFormat;
use App\Services\Import\ImportNumberParser;
use App\Services\Import\ImportRow;
use App\Services\Import\ObservationsCleaner;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Formato exportado por la pagina anterior del Locro. 16 columnas, mapeadas
 * por NOMBRE de encabezado (no por posicion: reordenar columnas en el Excel
 * no rompe la deteccion ni el parseo):
 *
 * ID orden | Rover encargado | Nombre | Apellido | Telefono | Direccion |
 * Cod. Postal | Delivery | QTY | Importe | Salsas | Observaciones 2026 |
 * Observaciones 2025 | Dinero cobrado | A cobrar | Mercado pago SI/NO
 *
 * "A cobrar" no se persiste: el saldo pendiente real siempre se deriva de
 * Order::recalculateTotals() (total_amount - pagos reales), igual que en el
 * resto de la app.
 */
class LegacyExcelImportAdapter extends AbstractSingleSheetAdapter
{
    private const FINGERPRINT = ['id orden', 'qty', 'mercado pago si/no'];

    public static function format(): ImportFormat
    {
        return ImportFormat::LegacySite;
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
            $externalOrderId = $this->str($get('id orden'));

            if ($firstName === null && $lastName === null && $phone === null && $externalOrderId === null) {
                continue; // fila completamente vacia (cola de la planilla)
            }

            $rows[] = new ImportRow(
                sourceRowNumber: $i + 1,
                externalOrderId: $externalOrderId,
                clientHistoricalNumber: null,
                roverName: $this->str($get('rover encargado')),
                firstName: $firstName,
                lastName: $lastName,
                phoneRaw: $phone,
                address: $this->str($get('direccion')),
                postalCode: $this->str($get('cod. postal')),
                isDelivery: ImportNumberParser::toBool($get('delivery')),
                portions: ImportNumberParser::toNullableInt($get('qty')),
                totalAmount: ImportNumberParser::toNullableFloat($get('importe')),
                saucesQty: ImportNumberParser::toNullableInt($get('salsas')) ?? 0,
                observations: ObservationsCleaner::merge(
                    $this->str($get('observaciones 2025')),
                    $this->str($get('observaciones 2026')),
                    '2025',
                    '2026',
                ),
                amountCollected: ImportNumberParser::toNullableFloat($get('dinero cobrado')),
                paymentMethodHint: ImportNumberParser::toBool($get('mercado pago si/no')) ? 'mercado_pago' : 'efectivo',
                orderStatus: null,
                withdrawalStatus: null,
            );
        }

        return $rows;
    }
}
