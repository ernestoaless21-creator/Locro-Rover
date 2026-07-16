<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicityMaterial extends Model
{
    protected $fillable = [
        'year_id', 'publicity_category_id', 'title', 'description',
        'file_path', 'file_name', 'file_size', 'mime_type',
        'notes', 'material_date', 'uploaded_by',
    ];

    // file_path es un detalle interno de almacenamiento, nunca se manda al frontend.
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'file_size'     => 'integer',
            'material_date' => 'date:Y-m-d',
        ];
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PublicityCategory::class, 'publicity_category_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
