<?php

namespace App\Policies;

use App\Models\ClientAssignment;
use App\Models\User;

/**
 * Fase 6A. Autorizacion de las asignaciones anuales de clientes / call
 * center. Regla estructural (seccion 2 y 9):
 * - Cualquier usuario operativo activo puede VER todas, filtrar, buscar,
 *   actualizar seguimiento (estado/observaciones, nunca el responsable), y
 *   autoasignarse una que este libre.
 * - Solo Logistica/Jefe de Logistica/Admin pueden TRANSFERIR una asignacion
 *   ya asignada a otro usuario, gestionar el numero historico, generar desde
 *   la edicion anterior, y hacer asignacion masiva/reparto equitativo.
 */
class ClientAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('asignaciones.ver');
    }

    public function view(User $user, ClientAssignment $assignment): bool
    {
        return $user->can('asignaciones.ver');
    }

    /**
     * Actualizar el seguimiento (estado de contacto, observaciones, quien
     * llamo por ultima vez). NUNCA toca assigned_user_id: eso pasa por
     * selfAssign/transfer, mas abajo.
     */
    public function updateContact(User $user, ClientAssignment $assignment): bool
    {
        return $user->can('asignaciones.ver');
    }

    /**
     * Autoasignarse una asignacion SIN responsable. Solo a si mismo, y solo
     * si esta libre (si ya tiene responsable, esto no aplica: es transfer()).
     *
     * Un cliente fuera de la base activa (ver Client::deactivate) no se debe
     * poder asignar a un responsable: es exactamente el trabajo de
     * seguimiento futuro que is_active=false esta pensado para frenar, y
     * dejarlo pasar prestaria a confusion (un Rover con un cliente
     * "asignado" que en realidad no se le debe contactar). No aplica a
     * syncFromOrder (una venta real siempre sincroniza el responsable, sin
     * pasar por esta policy) ni bloquea la reactivacion en si.
     */
    public function selfAssign(User $user, ClientAssignment $assignment): bool
    {
        return $user->can('asignaciones.ver')
            && $assignment->assigned_user_id === null
            && $assignment->client->is_active;
    }

    /**
     * Transferir una asignacion (tenga o no responsable actualmente) a
     * cualquier usuario activo. Solo Logistica/Jefe de Logistica/Admin.
     * Mismo motivo que selfAssign() para excluir clientes inactivos.
     */
    public function transfer(User $user, ClientAssignment $assignment): bool
    {
        return $user->can('asignaciones.transferir') && $assignment->client->is_active;
    }

    public function generate(User $user): bool
    {
        return $user->can('asignaciones.generar');
    }

    public function bulk(User $user): bool
    {
        return $user->can('asignaciones.masivo');
    }

    /**
     * Fase 21: exportar expone telefono/direccion (y montos con
     * 'finanzas.ver') de TODA la base de clientes. Antes reutilizaba
     * 'asignaciones.ver' (comun a TODOS los roles operativos), lo que
     * permitia a cualquier usuario activo bajarse el archivo. Permiso propio,
     * exclusivo de admin/jefe_logistica/logistica (ver seeder).
     */
    public function export(User $user): bool
    {
        return $user->can('clientes.exportar');
    }
}
