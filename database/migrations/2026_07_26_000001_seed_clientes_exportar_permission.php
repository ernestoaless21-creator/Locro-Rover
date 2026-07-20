<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fase 21: permiso 'clientes.exportar', separado de 'asignaciones.ver' (que
 * es comun a TODOS los roles operativos -- ver
 * RolesAndPermissionsSeeder::$commonOperational). Antes, cualquier usuario
 * activo (incluidos Compras/Infraestructura/Publicidad) podia exportar el
 * Excel de clientes/asignaciones via /assignments/export
 * (ClientAssignmentPolicy::export), exponiendo telefono/direccion (y montos
 * si ademas tenia 'finanzas.ver'). Mismo patron que
 * 2026_07_24_000001_seed_historico_importar_permission.php: se agrega tanto
 * al seeder (instalaciones nuevas) como aca (instalaciones ya desplegadas).
 *
 * Acceso: admin + jefe_logistica + logistica (mismo bloque que el resto de
 * $logisticsOnly en el seeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'clientes.exportar', 'guard_name' => 'web']);

        foreach (['jefe_logistica', 'logistica'] as $roleName) {
            Role::where('name', $roleName)->first()?->givePermissionTo('clientes.exportar');
        }

        $admin = Role::where('name', 'admin')->first();
        $admin?->syncPermissions(Permission::all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No se revierten permisos: podrian haberse asignado intencionalmente
        // a usuarios reales fuera de esta migracion.
    }
};
