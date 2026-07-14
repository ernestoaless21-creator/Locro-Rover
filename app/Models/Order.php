<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id', 'year_id', 'rover_id', 'status', 'withdrawal_status',
        'withdrawn_at', 'take_away', 'delivery_address', 'observations', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'withdrawn_at' => 'datetime',
        'take_away' => 'boolean',
        'total_amount' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'balance_due' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function rover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rover_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Marca el pedido como retirado, registrando quien y cuando (Fase 4).
     * Deliberadamente FUERA de $fillable: solo se setea via este metodo,
     * nunca por mass-assignment desde UpdateOrderRequest (evita que cualquiera
     * con 'pedidos.editar' pueda falsificar quien retiro un pedido).
     */
    public function markWithdrawn(int $userId, ?string $notes = null): void
    {
        $this->forceFill([
            'withdrawal_status' => 'retirado',
            'withdrawn_at' => now(),
            'withdrawn_by' => $userId,
            'withdrawal_notes' => $notes,
        ])->save();
    }

    public function unmarkWithdrawn(): void
    {
        $this->forceFill([
            'withdrawal_status' => 'no_retirado',
            'withdrawn_at' => null,
            'withdrawn_by' => null,
            'withdrawal_notes' => null,
        ])->save();
    }

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    /**
     * Recalcula y persiste total_portions, total_amount, total_paid y balance_due
     * a partir de las lineas (order_items) y los pagos (payments) reales.
     * Se llama desde OrderItemObserver y PaymentObserver, nunca se confia en el
     * frontend para estos valores.
     */
    public function recalculateTotals(): void
    {
        $portions = (int) $this->items()->where('product', 'locro')->sum('quantity');
        $amount = (string) $this->items()->sum('line_total');
        $paid = (string) $this->payments()->sum('amount');

        $this->forceFill([
            'total_portions' => $portions,
            'total_amount' => $amount,
            'total_paid' => $paid,
            'balance_due' => bcsub($amount, $paid, 2),
        ])->saveQuietly();
    }
}
