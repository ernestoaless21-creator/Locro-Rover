<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Porciones perdidas (Fase 5B). NO es una venta ni un pedido: no genera
 * importe, no genera saldo, no genera pagos. Solo descuenta stock disponible
 * para venta (ver DashboardController::index, calculo de "porciones aptas
 * para la venta").
 */
class Loss extends Model
{
    use HasFactory;

    protected $fillable = [
        'year_id', 'quantity', 'reason', 'created_by',
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
