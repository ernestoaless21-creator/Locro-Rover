<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pedidos.ver');
    }

    /**
     * Un usuario con 'pedidos.ver-todos' (admin, jefe_logistica, logistica) ve cualquier
     * pedido. Un Rover sin ese permiso SOLO puede ver pedidos donde el es el rover
     * asignado. Esto se aplica tanto para bloquear la ruta /orders/{order} como para
     * bloquear la carga de datos si alguien edita la URL a mano.
     */
    public function view(User $user, Order $order): bool
    {
        if (! $user->can('pedidos.ver')) {
            return false;
        }

        return $user->can('pedidos.ver-todos') || $order->rover_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('pedidos.crear');
    }

    public function update(User $user, Order $order): bool
    {
        if (! $user->can('pedidos.editar')) {
            return false;
        }

        return $user->can('pedidos.ver-todos') || $order->rover_id === $user->id;
    }

    public function delete(User $user, Order $order): bool
    {
        if (! $user->can('pedidos.eliminar')) {
            return false;
        }

        return $user->can('pedidos.ver-todos') || $order->rover_id === $user->id;
    }

    /**
     * Fase 6A, seccion 2: regla exacta de asignacion de responsable.
     * - Pedido SIN responsable (rover_id null): cualquier usuario con
     *   'pedidos.editar' puede autoasignarselo, pero SOLO a si mismo
     *   ($targetUserId === $user->id). No es una via para asignarselo a
     *   un tercero sin permiso de transferencia.
     * - Pedido YA asignado a alguien: solo quien tiene 'pedidos.asignar-rover'
     *   (admin/jefe_logistica/logistica) puede transferirlo, a cualquier
     *   destino.
     * $targetUserId es el usuario al que se lo quiere asignar/transferir
     * (obligatorio: sin esto no se puede distinguir autoasignacion de
     * asignacion a un tercero). Ver OrderController::store/update y
     * OrderBulkController::assignRover, que pasan [$order, $targetUserId].
     */
    public function assignRover(User $user, Order $order, ?int $targetUserId): bool
    {
        if ($order->rover_id === null) {
            return $targetUserId !== null && $user->can('pedidos.editar') && $targetUserId === $user->id;
        }

        // Ya asignado (incluye dejarlo sin responsable, $targetUserId === null):
        // solo quien puede transferir puede tocarlo.
        return $user->can('pedidos.asignar-rover');
    }

    /**
     * Marcar un pedido como retirado (individual o dentro de una accion masiva).
     * Mismo criterio de scope que update/delete: un Rover sin 'pedidos.ver-todos'
     * solo puede retirar SUS PROPIOS pedidos.
     */
    public function withdraw(User $user, Order $order): bool
    {
        if (! $user->can('pedidos.retirar')) {
            return false;
        }

        return $user->can('pedidos.ver-todos') || $order->rover_id === $user->id;
    }

    public function registerPayment(User $user, Order $order): bool
    {
        if (! $user->can('pagos.registrar')) {
            return false;
        }

        return $user->can('pedidos.ver-todos') || $order->rover_id === $user->id;
    }

    /**
     * Informacion economica (amount, total_paid, balance_due, recaudacion por medio de
     * pago) a nivel AGREGADO/global. Un rover nunca debe recibir estos totales globales,
     * aunque si puede ver el importe puntual de SU PROPIO pedido (eso no es informacion
     * economica global de la organizacion).
     */
    public function viewGlobalFinancials(User $user): bool
    {
        return $user->can('finanzas.ver');
    }
}
