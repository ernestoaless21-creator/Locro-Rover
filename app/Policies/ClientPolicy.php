<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\ClientAssignment;
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

    /**
     * Fase 18.1: antes cualquier usuario con 'clientes.editar' (comun a TODOS
     * los roles operativos) podia editar CUALQUIER cliente, sin distincion de
     * responsable. Ahora un Rover comun solo puede editar los clientes donde
     * el es el responsable asignado (client_year_assignments.assigned_user_id)
     * en la edicion ACTIVA (mismo criterio que "Mis clientes asignados" en
     * ClientController::index). Se reutiliza 'asignaciones.transferir'
     * (exclusivo de admin/jefe_logistica/logistica) como el permiso de
     * "puede editar cualquier cliente", igual criterio que
     * OrderPolicy::update con 'pedidos.asignar-rover': ya es la accion mas
     * privilegiada que existe sobre la asignacion de un cliente, asi que
     * reusarla evita crear un permiso nuevo redundante.
     */
    public function update(User $user, Client $client): bool
    {
        if (! $user->can('clientes.editar')) {
            return false;
        }

        if ($user->can('asignaciones.transferir')) {
            return true;
        }

        return ClientAssignment::query()
            ->where('client_id', $client->id)
            ->where('assigned_user_id', $user->id)
            ->whereHas('year', fn ($q) => $q->where('is_active', true))
            ->exists();
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
}
