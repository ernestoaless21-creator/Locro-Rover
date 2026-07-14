<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $ver      = Permission::firstOrCreate(['name' => 'actas.ver',      'guard_name' => 'web']);
        $gestionar = Permission::firstOrCreate(['name' => 'actas.gestionar', 'guard_name' => 'web']);

        // Todos los roles operativos pueden ver actas
        $operational = [
            'jefe_logistica', 'logistica',
            'jefe_compras', 'compras',
            'jefe_infraestructura', 'infraestructura',
            'jefe_publicidad', 'publicidad',
        ];
        foreach ($operational as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $role?->givePermissionTo($ver);
        }

        // Solo jefes y admin pueden gestionar actas
        $managers = [
            'jefe_logistica',
            'jefe_compras', 'jefe_infraestructura', 'jefe_publicidad',
        ];
        foreach ($managers as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $role?->givePermissionTo($gestionar);
        }

        // admin recibe todos los permisos
        $admin = Role::where('name', 'admin')->first();
        $admin?->syncPermissions(Permission::all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No se revierten permisos asignados.
    }
};
