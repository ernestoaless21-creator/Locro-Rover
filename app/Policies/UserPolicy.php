<?php

namespace App\Policies;

use App\Models\User;

/**
 * Gestion de usuarios y asignacion de roles (Fase 5C, Parte 1). Reutiliza el
 * permiso ya existente 'usuarios.gestionar' (ver RolesAndPermissionsSeeder),
 * que hoy solo lo tiene el rol 'admin', preservando la arquitectura real de
 * permisos del proyecto en vez de inventar uno nuevo.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('usuarios.gestionar');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('usuarios.gestionar');
    }
}
