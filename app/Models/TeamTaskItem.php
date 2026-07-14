<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamTaskItem extends Model
{
    protected $fillable = [
        'team_task_id', 'title', 'is_completed',
        'sort_order', 'completed_at', 'completed_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'sort_order'   => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TeamTask::class, 'team_task_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
