<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Garment extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    // Canonical (Polish) condition values — stored, validated, and AI-returned.
    // Maps from EN: new→nowy, like new→jak nowy, good→dobry, fair→średni, worn→znoszony.
    public const CONDITIONS = ['nowy', 'jak nowy', 'dobry', 'średni', 'znoszony'];

    // Canonical (Polish) category values — the allow-list the AI classifier must map to.
    // Mirrors the system prompt in GarmentClassifierService; anything outside → null.
    public const CATEGORIES = ['góra', 'dół', 'buty', 'akcesorium', 'okrycie wierzchnie'];

    protected $fillable = [
        'client_ref',
        'category',
        'brand',
        'color',
        'condition',
        'description',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')->singleFile();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
