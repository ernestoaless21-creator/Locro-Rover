<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InfrastructureLoan extends Model
{
    public const STATUSES = ['pending', 'returned'];

    protected $fillable = [
        'year_id', 'infrastructure_item_id', 'quantity', 'lender',
        'status', 'returned_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'integer',
            'returned_at' => 'date:Y-m-d',
        ];
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
