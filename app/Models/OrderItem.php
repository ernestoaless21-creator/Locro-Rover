<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'product', 'type', 'description', 'quantity',
        'unit_price', 'line_total', 'created_by',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Salvaguarda de defensa en profundidad: una linea 'regalo' SIEMPRE es $0,
     * sin importar que se cree directamente via Eloquent sin pasar por
     * PricingService. Para 'normal' y 'promocion' NO se recalcula aca: el
     * PricingService (app/Services/PricingService.php) es la unica fuente de
     * verdad del precio, para evitar dos lugares con la misma logica que
     * puedan divergir si mañana cambia una regla de precios.
     *
     * (Revision tecnica: antes este metodo tambien recalculaba 'normal' con
     * quantity*unit_price, duplicando lo que ya hace PricingService::calculateNormal.
     * Ese calculo daba el mismo resultado hoy, pero era codigo duplicado sin
     * beneficio real y un riesgo de divergencia futura.)
     */
    protected static function booted(): void
    {
        static::saving(function (OrderItem $item) {
            if ($item->type === 'regalo') {
                $item->line_total = '0.00';
            }
        });

        static::saved(fn (OrderItem $item) => $item->order->recalculateTotals());
        static::deleted(fn (OrderItem $item) => $item->order->recalculateTotals());
    }
}
