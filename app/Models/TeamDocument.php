<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamDocument extends Model
{
    protected $fillable = [
        'team', 'year_id', 'name', 'description',
        'file_path', 'file_name', 'file_size', 'mime_type', 'uploaded_by',
    ];

    // file_path is an internal storage detail, never needed on the frontend
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
