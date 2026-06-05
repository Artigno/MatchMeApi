<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarmentDeletion extends Model
{
    protected $fillable = [
        'user_id',
        'garment_id',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
