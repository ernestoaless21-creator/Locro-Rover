<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['name', 'phone', 'address', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function plannedItems(): HasMany
    {
        return $this->hasMany(PurchasePlanItem::class, 'planned_supplier_id');
    }

    public function actualItems(): HasMany
    {
        return $this->hasMany(PurchasePlanItem::class, 'actual_supplier_id');
    }
}
