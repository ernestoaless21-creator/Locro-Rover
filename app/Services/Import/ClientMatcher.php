<?php

namespace App\Services\Import;

use App\Models\Client;
use Illuminate\Support\Collection;

/**
 * Unico punto que busca clientes existentes durante una importacion. Precarga
 * en 2 consultas (una por telefono, una por numero historico) para evitar
 * N+1 sin importar cuantas filas tenga el archivo.
 *
 * El teléfono es la clave de dedup principal (pedido explicito del usuario);
 * el numero historico (solo presente en el formato Locro Rover, ver
 * ImportRow::$clientHistoricalNumber) tiene prioridad cuando esta disponible
 * porque es mas confiable en un escenario de restauracion total de datos.
 */
class ClientMatcher
{
    /**
     * @param  ImportRow[]  $rows
     * @return array{by_phone: Collection<string,Client>, by_historical_number: Collection<int,Client>}
     */
    public function preload(array $rows): array
    {
        $phones = collect($rows)
            ->map(fn (ImportRow $r) => $r->phoneRaw !== null ? Client::normalizePhone($r->phoneRaw) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $historicalNumbers = collect($rows)
            ->pluck('clientHistoricalNumber')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $byPhone = $phones === [] ? collect() : Client::query()->whereIn('phone', $phones)->get()->keyBy('phone');
        $byHistoricalNumber = $historicalNumbers === []
            ? collect()
            : Client::query()->whereIn('historical_number', $historicalNumbers)->get()->keyBy('historical_number');

        return ['by_phone' => $byPhone, 'by_historical_number' => $byHistoricalNumber];
    }

    /** @param  array{by_phone: Collection<string,Client>, by_historical_number: Collection<int,Client>}  $preloaded */
    public function match(ImportRow $row, array $preloaded): ?Client
    {
        if ($row->clientHistoricalNumber !== null) {
            $byHistorical = $preloaded['by_historical_number']->get($row->clientHistoricalNumber);
            if ($byHistorical !== null) {
                return $byHistorical;
            }
        }

        $normalizedPhone = $row->phoneRaw !== null ? Client::normalizePhone($row->phoneRaw) : null;

        return $normalizedPhone !== null ? $preloaded['by_phone']->get($normalizedPhone) : null;
    }
}
