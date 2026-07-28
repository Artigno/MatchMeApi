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

    // Canonical (English) condition values — stored, validated, and AI-returned.
    // Must match the mobile client's enum (context/research/backend-sync-requirements.md).
    public const CONDITIONS = ['new', 'like new', 'good', 'fair', 'worn'];

    // Canonical (English) category slugs — the allow-list the AI classifier must map to.
    // Aligned with the mobile client's category slugs; anything outside → null.
    public const CATEGORIES = ['tops', 'bottoms', 'footwear', 'accessories', 'outerwear'];

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
