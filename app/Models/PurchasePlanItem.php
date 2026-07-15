<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasePlanItem extends Model
{
    protected $fillable = [
        'year_id', 'purchase_product_id',
        'qty_1000', 'qty_1500', 'unit', 'estimated_total_price', 'planned_supplier_id',
        'actual_quantity', 'actual_total_price', 'actual_supplier_id',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qty_1000'               => 'decimal:3',
            'qty_1500'               => 'decimal:3',
            'actual_quantity'        => 'decimal:3',
            'estimated_total_price'  => 'decimal:2',
            'actual_total_price'     => 'decimal:2',
        ];
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PurchaseProduct::class, 'purchase_product_id');
    }

    public function plannedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'planned_supplier_id');
    }

    public function actualSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'actual_supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
