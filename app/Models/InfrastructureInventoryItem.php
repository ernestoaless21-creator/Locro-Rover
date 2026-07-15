<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InfrastructureInventoryItem extends Model
{
    protected $fillable = [
        'year_id', 'infrastructure_item_id',
        'needed_quantity', 'own_available_quantity', 'own_to_repair_quantity',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'needed_quantity'         => 'integer',
            'own_available_quantity'  => 'integer',
            'own_to_repair_quantity'  => 'integer',
        ];
    }

    /**
     * own_available_quantity ya incluye físicamente las unidades a reparar
     * (ver sección 5 del prompt de la fase): las útiles son las que quedan
     * fuera de esa cuenta. Nunca negativo.
     */
    protected function ownUsefulQuantity(): Attribute
    {
        return Attribute::get(fn () => max(0, $this->own_available_quantity - $this->own_to_repair_quantity));
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InfrastructureItem::class, 'infrastructure_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
