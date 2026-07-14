<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Porciones regaladas o donadas (Fase 5B). NO es un pedido: no genera
 * importe, no genera saldo, no genera pagos, no cuenta como venta ni como
 * recaudacion. Solo descuenta stock disponible para venta (ver
 * DashboardController::index, calculo de "porciones aptas para la venta").
 */
class Gift extends Model
{
    use HasFactory;

    protected $fillable = [
        'year_id', 'recipient_name', 'quantity', 'notes', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
