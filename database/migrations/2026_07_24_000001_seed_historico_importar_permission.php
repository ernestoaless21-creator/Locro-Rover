<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fase P2: permiso 'historico.importar' (importacion masiva de clientes/
 * pedidos desde Excel). Mismo patron que
 * 2026_07_16_000002_seed_team_tasks_permissions.php: se agrega tanto al
 * seeder (instalaciones nuevas) como aca (instalaciones ya desplegadas), para
 * que "php artisan migrate" alcance sin re-correr el seeder a mano.
 *
 * Acceso: admin + jefe_logistica (mismo bloque administrativo que ya tiene
 * parametros.gestionar/anios.gestionar/documentos.gestionar/auditoria.ver).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'historico.importar', 'guard_name' => 'web']);

        $jefeLogistica = Role::where('name', 'jefe_logistica')->first();
        $jefeLogistica?->givePermissionTo('historico.importar');

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
