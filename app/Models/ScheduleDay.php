<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleDay extends Model
{
    protected $fillable = [
        'year_id', 'date', 'title', 'description', 'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date'       => 'date:Y-m-d',
            'sort_order' => 'integer',
        ];
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ScheduleActivity::class)->orderedChronologically();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
