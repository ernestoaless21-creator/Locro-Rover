<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsCategory extends Model
{
    protected $fillable = ['name'];

    public function records(): HasMany
    {
        return $this->hasMany(LogisticsRecord::class);
    }
}
