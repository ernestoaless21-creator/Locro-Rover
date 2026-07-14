<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserRoleRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Fase 5C / 6A — seccion "Usuarios": ver quien se registro, asignar/cambiar
 * su unico rol operativo, y activar/desactivar usuarios sin borrar su
 * historial (Fase 6A, seccion 3).
 */
class UserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active', 'created_at'])
            ->load('roles:id,name')
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at,
                'role' => $user->roles->first()?->name,
                // Un rol "legacy" (rover/jefe_equipo, Fase 6A seccion 14) se
                // muestra igual en la fila del usuario que lo tiene, aunque
                // ya no se ofrezca como opcion nueva en el selector.
                'is_legacy_role' => in_array($user->roles->first()?->name, RolesAndPermissionsSeeder::LEGACY_ROLES, true),
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            // Solo se ofrecen los 9 roles operativos nuevos para asignar/cambiar
            // (Fase 6A, seccion 14): los roles legacy NO se ofrecen para nuevas
            // asignaciones, aunque sigan preservados en la base si ya existen.
            'roles' => Role::whereIn('name', RolesAndPermissionsSeeder::OPERATIONAL_ROLES)
                ->orderBy('name')
                ->pluck('name'),
            'authUserId' => auth()->id(),
        ]);
    }

    /**
     * Asigna o cambia el rol operativo de un usuario (un solo rol, sync
     * completo). PROTECCION: si el usuario objetivo es actualmente el UNICO
     * admin activo del sistema y el cambio le quita el rol admin, se rechaza.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        $newRole = $request->validated('role');

        $wasAdmin = $user->hasRole('admin');
        $activeAdminCount = User::role('admin')->active()->count();

        if ($wasAdmin && $newRole !== 'admin' && $activeAdminCount <= 1 && $user->is_active) {
            return back()->with('error', 'No se puede quitar el rol admin al unico administrador activo del sistema.');
        }

        $user->syncRoles([$newRole]);

        return back()->with('success', "Rol de {$user->name} actualizado a \"{$newRole}\".");
    }

    /**
     * Fase 6A, seccion 3: desactiva un usuario sin borrar su historial
     * (pedidos, asignaciones, pagos registrados quedan intactos: is_active
     * es la unica columna que cambia, ver User::deactivate()).
     *
     * PROTECCIONES pedidas explicitamente:
     * - Un admin no puede dejar el sistema sin NINGUN admin activo.
     * - Un usuario no puede desactivarse a si mismo si eso lo deja sin
     *   posibilidad de administrar el sistema (unico admin activo).
     */
    public function deactivate(User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        if ($user->hasRole('admin')) {
            $activeAdminCount = User::role('admin')->active()->count();

            if ($activeAdminCount <= 1) {
                return back()->with('error', 'No se puede desactivar al unico administrador activo del sistema.');
            }
        }

        $user->deactivate();

        return back()->with('success', "{$user->name} desactivado.");
    }

    public function reactivate(User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $user->reactivate();

        return back()->with('success', "{$user->name} reactivado.");
    }
}
