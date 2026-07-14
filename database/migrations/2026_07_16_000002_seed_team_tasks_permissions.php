<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migración de datos: crea el permiso tareas.ver y lo asigna a los roles
 * operativos correctos, de modo que php artisan migrate sea suficiente para
 * activar la Fase 9 sin re-ejecutar el seeder manualmente.
 *
 * Contexto: RolesAndPermissionsSeeder fue modificado en Fase 9 para agregar
 * tareas.ver a $commonOperational y tareas.gestionar-propio-equipo a los
 * roles jefe_*. Pero si el seeder no se vuelve a correr contra una BD
 * existente, esos permisos no llegan a la tabla permissions y Gate::authorize
 * falla con 403 para todos los usuarios. Esta migración resuelve eso.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $tareaVer = Permission::firstOrCreate(['name' => 'tareas.ver', 'guard_name' => 'web']);
        $tareaGestionar = Permission::firstOrCreate(['name' => 'tareas.gestionar-propio-equipo', 'guard_name' => 'web']);

        // tareas.ver → todos los roles de equipo (equivale a $commonOperational)
        $memberRoles = ['jefe_logistica', 'logistica', 'jefe_compras', 'compras', 'jefe_infraestructura', 'infraestructura', 'jefe_publicidad', 'publicidad'];
        foreach ($memberRoles as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $role?->givePermissionTo($tareaVer);
        }

        // tareas.gestionar-propio-equipo → solo jefes de equipo
        $jefeRoles = ['jefe_logistica', 'jefe_compras', 'jefe_infraestructura', 'jefe_publicidad'];
        foreach ($jefeRoles as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $role?->givePermissionTo($tareaGestionar);
        }

        // admin recibe todos los permisos (incluyendo los recien creados)
        $admin = Role::where('name', 'admin')->first();
        $admin?->syncPermissions(Permission::all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No se revierten permisos: podrían haberse asignado intencionalmente
        // a usuarios reales fuera de esta migración.
    }
};
