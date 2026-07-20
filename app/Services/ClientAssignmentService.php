<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAssignment;
use App\Models\Order;
use App\Models\User;
use App\Models\Year;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6A. Centraliza la logica de negocio de las asignaciones anuales de
 * clientes: sincronizacion con pedidos reales, generacion desde la edicion
 * anterior (idempotente), asignacion manual masiva y reparto equitativo.
 */
class ClientAssignmentService
{
    /**
     * Fase 7 (correccion 2): decision de arquitectura ACTUALIZADA y explicita,
     * reemplazando la de la correccion anterior.
     *
     * El usuario definio la regla de negocio exacta que quiere: "orders.rover_id"
     * y "client_year_assignments.assigned_user_id" representan al MISMO
     * responsable operativo de un cliente dentro de una edicion, y deben estar
     * SIEMPRE sincronizados en ambas direcciones. La razon de negocio: dar
     * continuidad (que el mismo Rover siga al mismo cliente) y evitar que
     * distintos Rovers contacten repetidamente a la misma persona.
     *
     * Esto REEMPLAZA la regla anterior ("nunca pisa una asignacion ya de OTRO
     * usuario"): ahora la ULTIMA ACCION REAL gana, sea una venta (crear/editar
     * un pedido con un rover) o una transferencia/autoasignacion explicita
     * desde Clientes o desde la pantalla de Asignaciones. Ejemplo del prompt:
     * si en 2026 Ernesto le vende a un cliente que estaba (por continuidad
     * heredada de 2025) asignado a Juan, la venta de Ernesto pasa a ser la
     * fuente de verdad: Ernesto queda como responsable, y TODOS los demas
     * pedidos de ese cliente en ESA MISMA edicion se actualizan para
     * mostrar a Ernesto tambien (nunca se tocan pedidos de otros anios).
     *
     * IMPORTANTE (pedido explicito del usuario): esto NUNCA toca `created_by`
     * de ningun pedido (quien registro originalmente la operacion es un dato
     * de auditoria inmutable); solo se actualiza `rover_id` (el responsable
     * ACTUAL, que puede cambiar con el tiempo) y `updated_by`.
     *
     * Se ejecuta dentro de una transaccion (evita estados parciales: la
     * asignacion y los N pedidos hermanos se actualizan todos o ninguno).
     */
    public function syncResponsibleForClientYear(int $clientId, int $yearId, ?int $roverId, ?int $actingUserId = null): void
    {
        if ($roverId === null) {
            // Desasignar (dejar sin responsable) es una decision distinta y
            // mas delicada (podria vaciar la continuidad de un cliente por
            // error); esta correccion no la pide, asi que no se actua.
            return;
        }

        DB::transaction(function () use ($clientId, $yearId, $roverId, $actingUserId) {
            $assignment = ClientAssignment::firstOrNew(['client_id' => $clientId, 'year_id' => $yearId]);

            if (! $assignment->exists) {
                $assignment->contact_status = ClientAssignment::STATUS_PENDIENTE;
                $assignment->created_by = $actingUserId;
            }

            $assignment->assigned_user_id = $roverId;
            $assignment->updated_by = $actingUserId ?? $assignment->updated_by;
            $assignment->save();

            // Propaga a TODOS los demas pedidos del MISMO cliente Y la MISMA
            // edicion (nunca otros anios, ver where year_id). No se usa
            // Eloquent::save() por fila (serian N updates + N saving events
            // innecesarios): un solo UPDATE masivo es suficiente porque
            // rover_id no dispara ningun recalculo de totales/salsas.
            Order::query()
                ->where('client_id', $clientId)
                ->where('year_id', $yearId)
                ->where(function ($q) use ($roverId) {
                    $q->whereNull('rover_id')->orWhere('rover_id', '!=', $roverId);
                })
                ->update(['rover_id' => $roverId, 'updated_by' => $actingUserId]);
        });
    }

    /**
     * Seccion 7: cuando se crea/edita un pedido real para un cliente en una
     * edicion, se reutiliza la asignacion anual existente o se crea una
     * nueva, y el estado de contacto pasa a 'pedido_realizado' (una venta real
     * siempre implica eso, sin importar el estado de seguimiento anterior).
     *
     * El responsable efectivo (assigned_user_id) se sincroniza siempre que el
     * pedido tenga rover, delegando en syncResponsibleForClientYear (ver
     * docblock de ese metodo para la decision de arquitectura completa).
     */
    public function syncFromOrder(Order $order): ClientAssignment
    {
        $actingUserId = $order->updated_by ?? $order->created_by;

        if ($order->rover_id !== null) {
            $this->syncResponsibleForClientYear($order->client_id, $order->year_id, $order->rover_id, $actingUserId);
        }

        $this->reactivateClientIfActiveEdition($order);

        $assignment = ClientAssignment::firstOrNew([
            'client_id' => $order->client_id,
            'year_id' => $order->year_id,
        ]);

        if (! $assignment->exists) {
            $assignment->contact_status = ClientAssignment::STATUS_PENDIENTE;
            $assignment->created_by = $actingUserId;
        }

        $assignment->contact_status = ClientAssignment::STATUS_PEDIDO_REALIZADO;
        $assignment->updated_by = $actingUserId;
        $assignment->save();

        return $assignment->refresh();
    }

    /**
     * Reactivacion automatica (decision de arquitectura, ver informe de la
     * correccion "gestion de clientes"): un cliente inactivo vuelve a
     * is_active=true SOLO cuando el pedido que dispara este sync pertenece a
     * la edicion ACTIVA -- nunca a una historica. Cubre con una unica
     * condicion los dos casos que NO deben reactivar a nadie: una
     * importacion historica (HistoricalImportController/ImportService, que
     * puede apuntar a cualquier año) y una carga/correccion manual de un
     * pedido en una edicion pasada (OrderController::store con
     * 'anios.gestionar'). Ambos representan reconstruccion de hechos del
     * pasado, no una relacion comercial nueva. UPDATE condicional directo
     * (no carga el modelo Client): es un no-op si el cliente ya estaba
     * activo, sin instanciar/guardar una fila que no cambia.
     */
    private function reactivateClientIfActiveEdition(Order $order): void
    {
        if (! $order->year()->value('is_active')) {
            return;
        }

        Client::query()
            ->where('id', $order->client_id)
            ->where('is_active', false)
            ->update([
                'is_active' => true,
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ]);
    }

    /**
     * Fase 7 (correccion): se mantiene por compatibilidad con los llamadores
     * existentes (OrderController::update, OrderBulkController::assignRover),
     * pero ahora delega enteramente en syncResponsibleForClientYear, que
     * ademas propaga a TODOS los demas pedidos del mismo cliente/edicion (ver
     * docblock de ese metodo). Antes solo actualizaba la asignacion.
     */
    public function syncResponsibleFromOrder(Order $order): void
    {
        if ($order->rover_id === null) {
            return;
        }

        $this->syncResponsibleForClientYear($order->client_id, $order->year_id, $order->rover_id, $order->updated_by ?? $order->created_by);
    }

    /**
     * Seccion 8: "Generar asignaciones desde edicion anterior". Copia las
     * asignaciones de $from a $to de clientes con is_active=true (no solo las
     * de clientes que compraron, pero SI excluye a los que se marcaron fuera
     * de la base activa -- ver Client::deactivate: es exactamente el
     * mecanismo automatico que ese estado esta pensado para frenar). Si el
     * responsable original sigue activo, se conserva; si esta inactivo, la
     * nueva asignacion queda sin responsable. Nunca duplica ni sobreescribe
     * una asignacion cliente/anio que ya exista en destino (idempotente).
     * Solo copia la asignacion en si: NO copia pedidos, pagos, importes ni
     * porciones.
     *
     * @return array{total_origin:int, kept_responsible:int, unassigned_inactive:int, already_existed:int}
     */
    public function generateFromPreviousYear(Year $from, Year $to, bool $dryRun = false, ?int $actingUserId = null): array
    {
        $sourceAssignments = ClientAssignment::query()
            ->where('year_id', $from->id)
            ->whereHas('client', fn ($q) => $q->where('is_active', true))
            ->get(['client_id', 'assigned_user_id']);

        $existingClientIds = ClientAssignment::query()
            ->where('year_id', $to->id)
            ->whereIn('client_id', $sourceAssignments->pluck('client_id'))
            ->pluck('client_id')
            ->flip();

        $activeUserIds = User::query()->active()->pluck('id')->flip();

        $summary = [
            'total_origin' => $sourceAssignments->count(),
            'kept_responsible' => 0,
            'unassigned_inactive' => 0,
            'already_existed' => 0,
        ];

        foreach ($sourceAssignments as $source) {
            if (isset($existingClientIds[$source->client_id])) {
                $summary['already_existed']++;

                continue;
            }

            $keepsResponsible = $source->assigned_user_id !== null && isset($activeUserIds[$source->assigned_user_id]);

            if ($source->assigned_user_id !== null) {
                $keepsResponsible ? $summary['kept_responsible']++ : $summary['unassigned_inactive']++;
            }

            if (! $dryRun) {
                // La restriccion UNIQUE (client_id, year_id) actua como
                // proteccion final ante una carrera entre dos ejecuciones
                // concurrentes de esta misma accion.
                ClientAssignment::firstOrCreate(
                    ['client_id' => $source->client_id, 'year_id' => $to->id],
                    [
                        'assigned_user_id' => $keepsResponsible ? $source->assigned_user_id : null,
                        'contact_status' => ClientAssignment::STATUS_PENDIENTE,
                        'created_by' => $actingUserId,
                        'updated_by' => $actingUserId,
                    ]
                );
            }
        }

        return $summary;
    }

    public function generateFromPreviousYearPreview(Year $from, Year $to): array
    {
        return $this->generateFromPreviousYear($from, $to, dryRun: true);
    }

    /**
     * Ejecuta la generacion dentro de una transaccion (seccion 8: "usar
     * transacciones").
     */
    public function executeGenerateFromPreviousYear(Year $from, Year $to, int $actingUserId): array
    {
        return DB::transaction(fn () => $this->generateFromPreviousYear($from, $to, dryRun: false, actingUserId: $actingUserId));
    }

    /**
     * Seccion 9.1/9.2: asignacion manual/masiva. Solo aplica a asignaciones
     * SIN responsable (las que ya tienen uno se transfieren aparte, con
     * permiso distinto). Devuelve cuantas se asignaron y cuantas se
     * ignoraron por ya tener responsable.
     */
    public function bulkAssign(array $assignmentIds, int $targetUserId, int $actingUserId): array
    {
        return DB::transaction(function () use ($assignmentIds, $targetUserId, $actingUserId) {
            $assignments = ClientAssignment::whereIn('id', $assignmentIds)->get();
            $assigned = 0;
            $skipped = 0;

            foreach ($assignments as $assignment) {
                if ($assignment->assigned_user_id !== null) {
                    $skipped++;

                    continue;
                }

                // Fase 7 (correccion): reutiliza el mismo sincronizador que
                // transferir/autoasignar/vender, para que tambien propague a
                // los pedidos (si los hubiera) de este cliente en esta
                // edicion (ver syncResponsibleForClientYear).
                $this->syncResponsibleForClientYear($assignment->client_id, $assignment->year_id, $targetUserId, $actingUserId);
                $assigned++;
            }

            return ['assigned' => $assigned, 'skipped' => $skipped];
        });
    }

    /**
     * Seccion 9.3: reparto equitativo entre los usuarios ACTIVOS elegidos
     * (nunca entre "todos los usuarios"). Distribucion determinista: se
     * ordenan las asignaciones por id (orden estable) y se reparten en
     * ronda entre los usuarios en el orden en que fueron seleccionados; el
     * sobrante (si el total no es multiplo exacto de la cantidad de
     * usuarios) queda para los PRIMEROS usuarios de la lista recibida, en
     * ese orden. Ejemplo documentado en el prompt: 17 clientes entre 4
     * usuarios -> 5,4,4,4 (el primero de la lista recibe el sobrante).
     * Solo asignaciones SIN responsable participan del reparto.
     */
    public function bulkDistribute(array $assignmentIds, array $userIds, int $actingUserId): array
    {
        return DB::transaction(function () use ($assignmentIds, $userIds, $actingUserId) {
            // Se reordena en PHP (no con SQL FIELD(), que no es portable entre
            // motores) respetando el orden en que el admin selecciono a los
            // usuarios: el primero de esa lista recibe el sobrante del reparto.
            $activeIds = User::query()->whereIn('id', $userIds)->active()->pluck('id')->all();
            $activeUserIds = collect($userIds)
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => in_array($id, $activeIds, true))
                ->unique()
                ->values();

            if ($activeUserIds->isEmpty()) {
                return ['assigned' => 0, 'skipped' => 0, 'per_user' => [], 'ignored_inactive_users' => count($userIds)];
            }

            $assignments = ClientAssignment::whereIn('id', $assignmentIds)
                ->whereNull('assigned_user_id')
                ->orderBy('id')
                ->get();

            $skipped = count($assignmentIds) - $assignments->count();
            $perUser = array_fill_keys($activeUserIds->all(), 0);
            $userCount = $activeUserIds->count();

            foreach ($assignments as $index => $assignment) {
                $targetUserId = $activeUserIds[$index % $userCount];
                // Fase 7 (correccion): idem bulkAssign, propaga a pedidos.
                $this->syncResponsibleForClientYear($assignment->client_id, $assignment->year_id, $targetUserId, $actingUserId);
                $perUser[$targetUserId]++;
            }

            return [
                'assigned' => $assignments->count(),
                'skipped' => $skipped,
                'per_user' => $perUser,
                'ignored_inactive_users' => count($userIds) - $userCount,
            ];
        });
    }
}
