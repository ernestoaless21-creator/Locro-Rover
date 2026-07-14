<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientObservation extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'year_id', 'observation', 'created_by'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(Year::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
