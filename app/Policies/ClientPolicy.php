<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('clientes.ver');
    }

    public function view(User $user, Client $client): bool
    {
        return $user->can('clientes.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('clientes.crear');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can('clientes.editar');
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->can('clientes.eliminar');
    }

    public function restore(User $user, Client $client): bool
    {
        return $user->can('clientes.eliminar');
    }

    /**
     * Eliminacion masiva: mismo permiso que eliminar individual, pero se
     * evalua explicitamente en el Controller antes de aplicar el soft delete
     * a cada uno de los IDs recibidos (nunca se confia en que el frontend
     * ya filtro lo que el usuario puede borrar).
     */
    public function bulkDelete(User $user): bool
    {
        return $user->can('clientes.eliminar');
    }

    /**
     * Fase 6A, seccion 5: el numero historico permanente del cliente solo lo
     * puede gestionar (crear/editar) Logistica/Jefe de Logistica/Admin. Los
     * demas usuarios operativos pueden VERLO (viene incluido en 'clientes.ver'),
     * pero no modificarlo.
     */
    public function manageHistoricalNumber(User $user): bool
    {
        return $user->can('asignaciones.numero-historico');
    }
}
