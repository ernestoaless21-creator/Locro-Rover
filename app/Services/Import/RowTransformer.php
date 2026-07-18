<?php

namespace App\Services\Import;

/**
 * Convierte una ImportRow (ya validada) en los arrays de atributos que
 * esperan Client::create/Order::create/OrderItem::create/Payment::create.
 * Puro, sin acceso a la base: recibe los ids ya resueltos (cliente, rover,
 * medio de pago) como parametros en vez de resolverlos el mismo (esa parte
 * es de ClientMatcher/RoverMatcher/ImportService).
 */
class RowTransformer
{
    /**
     * clients.first_name/last_name son NOT NULL (ver migracion de la tabla)
     * y ese esquema no se toca. El Excel historico real trae filas con el
     * apellido (a veces el nombre) vacio -- RowValidator solo exige que
     * ALGUNO de los dos venga completo (ver "Falta nombre y apellido"), asi
     * que el otro puede llegar null aca. Se completa con este placeholder
     * SOLO al crear el cliente durante la importacion (nunca se toca un
     * cliente ya existente que se reutiliza, ni el resto de la app: alta
     * manual sigue exigiendo ambos campos via StoreClientRequest), para que
     * alguien lo pueda ubicar y completar despues sin perder el registro.
     */
    private const MISSING_NAME_PLACEHOLDER = 'COMPLETAR';

    /** @return array<string,mixed> */
    public function toClientAttributes(ImportRow $row, int $userId): array
    {
        return [
            'first_name' => $row->firstName ?? self::MISSING_NAME_PLACEHOLDER,
            'last_name' => $row->lastName ?? self::MISSING_NAME_PLACEHOLDER,
            'phone' => $row->phoneRaw,
            'address' => $row->address,
            'postal_code' => $row->postalCode,
            'created_by' => $userId,
            'updated_by' => $userId,
        ];
    }

    /**
     * La pagina vieja registraba TODOS los clientes contactados, incluso los
     * que no compraron ese año (QTY vacio): "no atendio", "no quiso
     * comprar", "quedo pendiente", etc. Esas filas se importan igual (se
     * conserva el historial completo, no son un error), pero como pedido de
     * 0 porciones. Este helper centraliza "cuantas porciones tiene
     * realmente esta fila" para que toOrderAttributes/toOrderItemsAttributes/
     * toPaymentAttributes usen exactamente el mismo criterio.
     */
    private function effectivePortions(ImportRow $row): int
    {
        return max(0, (int) ($row->portions ?? 0));
    }

    /** @return array<string,mixed> */
    public function toOrderAttributes(ImportRow $row, int $clientId, int $yearId, ?int $roverId, int $userId): array
    {
        $isDelivery = $row->isDelivery;

        return [
            'client_id' => $clientId,
            'year_id' => $yearId,
            'rover_id' => $roverId,
            // Sin compra (0 porciones): se persiste como 'cancelado', el
            // MISMO status que ya usa toda la app (Dashboard, contadores,
            // ranking, export de Asignaciones) para excluir de "ventas
            // reales" automaticamente, sin tener que tocar cada uno de esos
            // lugares por separado (ver PricingService y OrderController,
            // que ya filtran where('status', '!=', 'cancelado')). Un
            // orderStatus explicito del adapter (hoy solo Locro Rover, que
            // exporta el status real) siempre tiene prioridad sobre esto.
            'status' => $row->orderStatus ?? ($this->effectivePortions($row) > 0 ? 'confirmado' : 'cancelado'),
            'withdrawal_status' => $row->withdrawalStatus ?? 'no_retirado',
            'take_away' => ! $isDelivery,
            'delivery_address' => $isDelivery ? $row->address : null,
            'observations' => $row->observations,
            'created_by' => $userId,
            'updated_by' => $userId,
        ];
    }

    /**
     * Lineas de order_items (sin order_id: se agrega al crearlas sobre la
     * relacion). Nunca pasa por PricingService a proposito: es un importe
     * historico ya cerrado, y los importes ya persistidos no deben
     * recalcularse con los parametros de precio del año ACTUAL (ver
     * docblock de PricingService, seccion "Integridad historica"). Las
     * salsas, igual que en el resto de la app, nunca tienen cargo propio.
     *
     * Sin compra (0 porciones): CERO lineas, ni siquiera de salsas. Mismo
     * estado final que ya produce PricingService::syncPortionsForOrder
     * cuando un pedido existente se edita a 0 porciones (borra ambas
     * lineas), asi que no se introduce un concepto nuevo en la app.
     *
     * @return array<int,array<string,mixed>>
     */
    public function toOrderItemsAttributes(ImportRow $row, int $userId): array
    {
        $portions = $this->effectivePortions($row);

        if ($portions === 0) {
            return [];
        }

        $amount = round((float) ($row->totalAmount ?? 0), 2);
        $unitPrice = round($amount / $portions, 2);

        $items = [[
            'product' => 'locro',
            'type' => 'personalizado',
            'description' => 'Importado',
            'quantity' => $portions,
            'unit_price' => number_format($unitPrice, 2, '.', ''),
            'line_total' => number_format($amount, 2, '.', ''),
            'created_by' => $userId,
        ]];

        if (($row->saucesQty ?? 0) > 0) {
            $items[] = [
                'product' => 'salsas',
                'type' => 'normal',
                'description' => null,
                'quantity' => (int) $row->saucesQty,
                'unit_price' => '0.00',
                'line_total' => '0.00',
                'created_by' => $userId,
            ];
        }

        return $items;
    }

    /** @return array<string,mixed>|null null si no hay nada cobrado para esta fila (o no hubo compra) */
    public function toPaymentAttributes(ImportRow $row, int $paymentMethodId, int $userId): ?array
    {
        // Sin compra: nunca se registra un pago, sin importar lo que diga
        // "Dinero cobrado" (no deberia haber cobro sin producto).
        if ($this->effectivePortions($row) === 0) {
            return null;
        }

        if ($row->amountCollected === null || $row->amountCollected <= 0) {
            return null;
        }

        return [
            'payment_method_id' => $paymentMethodId,
            'amount' => number_format($row->amountCollected, 2, '.', ''),
            'paid_at' => now(),
            'registered_by' => $userId,
            'notes' => 'Pago importado',
        ];
    }
}
