<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamTask extends Model
{
    public const TEAMS = ['logistica', 'compras', 'infraestructura', 'publicidad'];

    protected $fillable = [
        'team', 'year_id', 'title', 'description', 'notes',
        'optimal_date', 'due_date', 'is_completed',
        'sort_order', 'completed_at', 'completed_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
            'optimal_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
        ];
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TeamTaskItem::class, 'team_task_id');
    }
}
