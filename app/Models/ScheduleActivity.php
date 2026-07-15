<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleActivity extends Model
{
    public const STATUSES = ['pending', 'completed', 'skipped'];

    public const TEAMS = ['logistica', 'compras', 'infraestructura', 'publicidad'];

    protected $fillable = [
        'schedule_day_id', 'title', 'description',
        'start_time', 'end_time',
        'status', 'actual_date', 'actual_time', 'notes',
        'team', 'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'actual_date' => 'date:Y-m-d',
            'sort_order'  => 'integer',
        ];
    }

    // Strip seconds from TIME columns so frontend always gets "HH:MM"
    protected function startTime(): Attribute
    {
        return Attribute::get(fn ($v) => $v ? substr($v, 0, 5) : null);
    }

    protected function endTime(): Attribute
    {
        return Attribute::get(fn ($v) => $v ? substr($v, 0, 5) : null);
    }

    protected function actualTime(): Attribute
    {
        return Attribute::get(fn ($v) => $v ? substr($v, 0, 5) : null);
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(ScheduleDay::class, 'schedule_day_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Chronological order: activities without a scheduled start_time first
     * (preserving their manual sort_order among each other), then activities
     * with a start_time ordered ascending, using sort_order then id to break
     * ties on identical start_time values.
     */
    public function scopeOrderedChronologically(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->orderByRaw('start_time IS NULL DESC')
            ->orderBy('start_time')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
