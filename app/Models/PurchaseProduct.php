<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseProduct extends Model
{
    protected $fillable = [
        'purchase_category_id', 'name', 'unit', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PurchaseCategory::class, 'purchase_category_id');
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(PurchasePlanItem::class);
    }
}
