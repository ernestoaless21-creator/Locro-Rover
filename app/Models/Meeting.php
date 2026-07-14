<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $fillable = [
        'year_id', 'title', 'date',
        'development', 'secretary_id', 'secretary_name', 'otros_asistentes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function secretary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secretary_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(MeetingDecision::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class)->orderBy('user_name');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(TeamDocument::class, 'meeting_team_document');
    }
}
