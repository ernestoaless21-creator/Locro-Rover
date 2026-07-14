<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 6A. Asignacion anual de un Cliente a un usuario/Rover responsable de
 * contactarlo durante UNA edicion, mas su estado de seguimiento/call center.
 *
 * IMPORTANTE: esto NO es un pedido. Un cliente puede tener una asignacion sin
 * ningun pedido real (todavia no compro / no respondio), y un pedido real
 * siempre reutiliza o crea su asignacion correspondiente (ver
 * ClientAssignmentService::syncFromOrder). Actualizar el estado de contacto
 * u observaciones de seguimiento NUNCA transfiere la propiedad/asignacion
 * (assigned_user_id) del cliente: eso solo cambia via autoasignacion (si esta
 * libre) o transferencia explicita (permiso 'asignaciones.transferir').
 */
class ClientAssignment extends Model
{
    use HasFactory;

    protected $table = 'client_year_assignments';

    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_NO_RESPONDIO = 'no_respondio';
    public const STATUS_VOLVER_A_LLAMAR = 'volver_a_llamar';
    public const STATUS_NO_INTERESADO = 'no_interesado';
    public const STATUS_INTERESADO = 'interesado';
    public const STATUS_PEDIDO_REALIZADO = 'pedido_realizado';

    public const STATUSES = [
        self::STATUS_PENDIENTE => 'Pendiente',
        self::STATUS_NO_RESPONDIO => 'No respondió',
        self::STATUS_VOLVER_A_LLAMAR => 'Volver a llamar',
        self::STATUS_NO_INTERESADO => 'No interesado',
        self::STATUS_INTERESADO => 'Interesado',
        self::STATUS_PEDIDO_REALIZADO => 'Pedido realizado',
    ];

    protected $fillable = [
        'client_id', 'year_id', 'assigned_user_id', 'contact_status',
        'last_contacted_at', 'last_contacted_by', 'notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'last_contacted_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function lastContactedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_contacted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_user_id');
    }
}
