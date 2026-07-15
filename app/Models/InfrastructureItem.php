<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InfrastructureItem extends Model
{
    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InfrastructureInventoryItem::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(InfrastructureLoan::class);
    }
}
