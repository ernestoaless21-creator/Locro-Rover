<?php

namespace App\Http\Requests\Concerns;

/**
 * Fase 20 (bug de permisos): las acciones sobre pedidos (cobrar, retirar,
 * asignar rover) siguen siendo individuales para "todos venden" (un rover
 * puede cobrar/retirar UN pedido propio via el checkbox de la fila, que
 * pega contra este mismo endpoint con un solo order_id). Pero actuar sobre
 * VARIOS pedidos a la vez (la barra de "Acciones masivas" de Orders/Index.vue)
 * es exclusivo de Logistica: se exige 'pedidos.acciones-masivas' SOLO cuando
 * el request trae mas de un order_id.
 */
trait RequiresBulkOrdersPermission
{
    protected function passesBulkOrdersGate(): bool
    {
        $orderIds = $this->input('order_ids');

        if (! is_array($orderIds) || count($orderIds) <= 1) {
            return true;
        }

        return $this->user()->can('pedidos.acciones-masivas');
    }
}
