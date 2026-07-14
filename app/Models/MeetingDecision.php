<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingDecision extends Model
{
    public const CATEGORIES = [
        'decision'           => 'Decisión',
        'aspecto_positivo'   => 'Aspecto positivo',
        'aspecto_a_mejorar'  => 'Aspecto a mejorar',
        'leccion_aprendida'  => 'Lección aprendida',
        'pendiente'          => 'Pendiente',
    ];

    public const TEAMS = ['logistica', 'compras', 'infraestructura', 'publicidad'];

    protected $fillable = [
        'meeting_id', 'text', 'category', 'team', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
