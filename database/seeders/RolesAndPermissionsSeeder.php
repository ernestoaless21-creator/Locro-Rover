<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Year;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Fase 6A: roles operativos nuevos, organizados por equipo (todos venden,
     * ver seccion 2 del prompt de la fase). Reemplazan a los roles genericos
     * 'rover' y 'jefe_equipo' para nuevas asignaciones, PERO esos roles viejos
     * NO se borran ni se les quita el rol a los usuarios que ya los tienen
     * (ver seccion 14: transicion segura). Un admin debe reasignarles
     * manualmente uno de los roles nuevos desde /users cuando corresponda.
     */
    public const OPERATIONAL_ROLES = [
        'admin',
        'jefe_logistica',
        'logistica',
        'jefe_compras',
        'compras',
        'jefe_infraestructura',
        'infraestructura',
        'jefe_publicidad',
        'publicidad',
    ];

    /**
     * Roles legacy que ya no se ofrecen para NUEVAS asignaciones (ver
     * UserController::index, que los excluye de la lista de roles
     * seleccionables), pero se preservan si ya existen en la base para no
     * romper usuarios/historial existentes.
     */
    public const LEGACY_ROLES = ['rover', 'jefe_equipo'];

    /**
     * Corre con: php artisan db:seed --class=RolesAndPermissionsSeeder
     * (o queda incluido en DatabaseSeeder::run)
     */
    public function run(): void
    {
        $permissions = [
            // Clientes y pedidos (operativo - TODOS los roles operativos, ver seccion 2 y 13)
            'clientes.ver', 'clientes.crear', 'clientes.editar', 'clientes.eliminar',
            'pedidos.ver', 'pedidos.crear', 'pedidos.editar', 'pedidos.eliminar',
            'pedidos.ver-todos', // ver pedidos de todos los rovers, no solo los propios
            'pedidos.asignar-rover', // Fase 6A: tambien es el permiso de TRANSFERIR un pedido ya asignado (ver OrderPolicy::assignRover)
            'pedidos.retirar',
            'pedidos.precio-excepcional',
            'pagos.registrar',
            // Regalos y perdidas: registros de stock independientes de pedidos.
            'regalos.gestionar',
            'perdidas.gestionar',
            // Fase 6A: asignaciones anuales de clientes / call center.
            'asignaciones.ver', // ver todas, filtrar, buscar, autoasignarse una libre, actualizar seguimiento, exportar
            'asignaciones.transferir', // transferir una asignacion YA asignada a otro usuario
            'asignaciones.numero-historico', // gestionar (crear/editar) el numero historico del cliente
            'asignaciones.generar', // "Generar asignaciones desde edicion anterior"
            'asignaciones.masivo', // asignacion manual masiva + reparto equitativo
            // Fase 6A: produccion real (elaboradas/regaladas/perdidas/aptas/disponibles)
            // es sensible y NO es lo mismo que 'finanzas.ver' (ver seccion 11).
            'produccion.ver',
            // Finanzas (critico - privado)
            'finanzas.ver',
            'gastos.gestionar',
            'proveedores.gestionar',
            // Organizacion interna
            'equipos.gestionar-propio',
            'equipos.gestionar-todos',
            'tareas.ver',
            'tareas.gestionar-propio-equipo',
            // Actas y reuniones
            'actas.ver',
            'actas.gestionar',
            // Cronograma operativo
            'cronograma.ver',
            'cronograma.gestionar',
            // Fase 14: planificacion de compras y proveedores.
            // 'proveedores.gestionar' YA estaba definido (ver mas arriba, seccion
            // Finanzas) y sin uso todavia: se reutiliza aca en vez de crear un
            // permiso paralelo para el mismo concepto.
            'compras.planificacion.ver',
            'compras.planificacion.gestionar',
            // Fase 15: inventario de infraestructura y prestamos.
            'infraestructura.inventario.ver',
            'infraestructura.inventario.gestionar',
            // Administracion
            'usuarios.gestionar',
            'roles.gestionar',
            'parametros.gestionar',
            'anios.gestionar',
            'documentos.gestionar',
            'auditoria.ver',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // --- Permisos comunes a TODOS los roles operativos (seccion 2 y 13:
        // "todos venden"): ver/crear pedidos y clientes, ver todos los
        // pedidos/clientes, pagos, retiro, y el seguimiento de asignaciones. ---
        //
        // Fase 15, Parte A: 'tareas.gestionar-propio-equipo' se movio aca
        // (antes solo la tenian los roles jefe_*). Ser jefe/responsable de un
        // equipo NO debe otorgar privilegios exclusivos de edicion: cualquier
        // integrante debe poder gestionar tareas/documentos de su propio
        // equipo. El scope real (SOLO el equipo propio, salvo admin) sigue
        // aplicandose en runtime via User::teamSlug() en
        // TeamTaskController/TeamTaskItemController/TeamDocumentController::
        // authorizeTeamAccess(), asi que este permiso amplio no abre acceso
        // cruzado entre equipos.
        $commonOperational = [
            'clientes.ver', 'clientes.crear', 'clientes.editar',
            'pedidos.ver', 'pedidos.crear', 'pedidos.editar',
            'pedidos.ver-todos', 'pedidos.retirar', 'pagos.registrar',
            'asignaciones.ver',
            'tareas.ver',
            'tareas.gestionar-propio-equipo',
            'actas.ver',
            'cronograma.ver',
            // Fase 14: la planificacion de compras se puede VER ampliamente
            // (no solo el equipo Compras la usa), igual que cronograma.ver;
            // gestionarla (planificacion.gestionar / proveedores.gestionar)
            // queda acotada a admin + integrantes de Compras mas abajo.
            'compras.planificacion.ver',
            // Fase 15: mismo criterio para el inventario de infraestructura.
            'infraestructura.inventario.ver',
        ];

        // --- Permisos exclusivos de admin/jefe_logistica/logistica (seccion 13). ---
        // Fase 7 (correccion 2), seccion 4: 'pedidos.eliminar' YA estaba
        // definido como permiso, pero no se le habia dado a NINGUN rol salvo
        // 'admin' (via Permission::all() mas abajo). Esa era la causa real de
        // "no puedo eliminar pedidos": el boton llamaba a un endpoint que
        // funcionaba, pero Gate::authorize('delete', $order) fallaba con 403
        // para cualquier usuario no-admin, silenciosamente (el frontend no
        // mostraba el error). Se agrega aca (Logistica/Jefe de Logistica),
        // ya que eliminar un pedido es una accion sensible del mismo nivel
        // que asignar-rover/precio-excepcional, no una accion comun de
        // cualquier Rover (ver informe de esta correccion para el detalle).
        $logisticsOnly = [
            'pedidos.asignar-rover', 'pedidos.precio-excepcional', 'pedidos.eliminar',
            'regalos.gestionar', 'perdidas.gestionar',
            'asignaciones.transferir', 'asignaciones.numero-historico',
            'asignaciones.generar', 'asignaciones.masivo',
            'produccion.ver', 'finanzas.ver',
        ];

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());

        // jefe_logistica conserva un bloque de privilegios administrativos
        // (gastos/parametros/anios/documentos/auditoria) que NO son
        // "herramientas de equipo" sino un rol de tipo adjunto-a-admin
        // preexistente: Fase 15 Parte A no lo toca (ver informe de la fase).
        // 'tareas.gestionar-propio-equipo' ya viene incluido via
        // $commonOperational, no hace falta repetirlo aca.
        $jefeLogistica = Role::firstOrCreate(['name' => 'jefe_logistica', 'guard_name' => 'web']);
        $jefeLogistica->syncPermissions([
            ...$commonOperational,
            ...$logisticsOnly,
            'gastos.gestionar', 'proveedores.gestionar',
            'parametros.gestionar', 'anios.gestionar', 'documentos.gestionar', 'auditoria.ver',
            'actas.gestionar',
            'cronograma.gestionar',
        ]);

        $logistica = Role::firstOrCreate(['name' => 'logistica', 'guard_name' => 'web']);
        $logistica->syncPermissions([
            ...$commonOperational,
            ...$logisticsOnly,
        ]);

        // Compras, Infraestructura y Publicidad: mismo set operativo base
        // ("todos venden"), sin los permisos exclusivos de Logistica.
        //
        // Fase 15, Parte A: jefe_{team} y {team} ahora reciben el MISMO set
        // de permisos de gestion de las herramientas propias del equipo
        // (Compras -> planificacion+proveedores; Infraestructura ->
        // inventario+prestamos). Ser jefe deja de ser condicion para poder
        // editar: solo sigue siendo jefe/responsable a nivel organizativo
        // (el rol jefe_* en si mismo, visible en /users, es la unica
        // informacion "historica" de responsable que se conserva - no hay
        // año/edicion asociado a esa asignacion). jefe_* conserva
        // exclusivamente 'actas.gestionar' y 'cronograma.gestionar', que son
        // herramientas COMPARTIDAS entre todos los equipos (no propias de
        // uno solo) y quedan fuera del alcance de este cambio.
        foreach (['compras', 'infraestructura', 'publicidad'] as $team) {
            $teamToolPermissions = match ($team) {
                'compras'         => ['compras.planificacion.gestionar', 'proveedores.gestionar'],
                'infraestructura' => ['infraestructura.inventario.gestionar'],
                default           => [],
            };

            $jefeRole = Role::firstOrCreate(['name' => "jefe_{$team}", 'guard_name' => 'web']);
            $jefeRole->syncPermissions([
                ...$commonOperational,
                'actas.gestionar',
                'cronograma.gestionar',
                ...$teamToolPermissions,
            ]);

            $teamRole = Role::firstOrCreate(['name' => $team, 'guard_name' => 'web']);
            $teamRole->syncPermissions([
                ...$commonOperational,
                ...$teamToolPermissions,
            ]);
        }

        // --- Roles legacy (Fase 6A, seccion 14): se preservan tal cual si ya
        // existen (no se les toca el set de permisos aca, para no ampliar
        // silenciosamente el acceso de usuarios que todavia no fueron
        // migrados por un admin a uno de los roles nuevos), y NO se crean
        // roles legacy nuevos si no existian ya en la base. Sin acciones aca:
        // se deja este comentario para dejar constancia de la decision. ---

        // Medios de pago iniciales.
        foreach (['Efectivo', 'Transferencia'] as $name) {
            PaymentMethod::firstOrCreate(
                ['slug' => str()->slug($name)],
                ['name' => $name, 'is_active' => true]
            );
        }

        // Anio activo por defecto (ajustar el numero real antes de usar en produccion).
        $currentYear = (int) date('Y');
        Year::firstOrCreate(
            ['year' => $currentYear],
            ['label' => "Locro {$currentYear}", 'is_active' => true, 'event_type' => 'locro']
        );
    }
}
