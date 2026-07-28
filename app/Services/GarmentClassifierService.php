<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GarmentClassifier;
use App\Exceptions\ClassifierTimeoutException;
use App\Exceptions\ClassifierUpstreamException;
use App\Models\Garment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GarmentClassifierService implements GarmentClassifier
{
    public function classify(string $base64Image, string $mimeType): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('services.openrouter.api_key'),
            ])->timeout(25)->post(config('services.openrouter.base_url').'/chat/completions', [
                'model' => config('services.openrouter.model'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$base64Image}",
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Przeanalizuj to ubranie i zwróć pola karty oferty zgodnie z instrukcją.',
                            ],
                        ],
                    ],
                ],
            ]);
        } catch (ConnectionException $e) {
            // Network-level failure or the 25s timeout elapsed → 504.
            throw new ClassifierTimeoutException('OpenRouter request timed out.', previous: $e);
        }

        if (! $response->successful()) {
            throw new ClassifierUpstreamException('OpenRouter API returned '.$response->status());
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content)) {
            throw new ClassifierUpstreamException('Unexpected response shape from OpenRouter.');
        }

        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            throw new ClassifierUpstreamException('Could not parse JSON from OpenRouter response.');
        }

        return $this->extractFields($parsed);
    }

    private function extractFields(array $data): array
    {
        return [
            'category' => $this->enumString($data['category'] ?? null, Garment::CATEGORIES),
            'brand' => $this->nullableString($data['brand'] ?? null),
            'color' => $this->nullableString($data['color'] ?? null),
            'condition' => $this->enumString($data['condition'] ?? null, Garment::CONDITIONS),
            'description' => $this->nullableString($data['description'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Normalize a string field against a Polish allow-list. Trims and lowercases
     * before the strict comparison so padded/mixed-case AI output still matches;
     * anything outside the set collapses to null (never plausible-but-wrong).
     *
     * @param  list<string>  $allowed
     */
    private function enumString(mixed $value, array $allowed): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($value), 'UTF-8');

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a garment listing assistant for a Polish-speaking marketplace. Analyze the provided garment photo and return a JSON object with exactly these five fields. Free-text fields ("color", "description") are written IN POLISH; enum fields ("category", "condition") use the exact English keys listed below:

- "category": one of the English values "tops", "bottoms", "footwear", "accessories", "outerwear", or null if unclear.
- "brand": the brand name verbatim as printed (e.g. "Zara", "Nike"), or null if not clearly visible. Do NOT translate brand names.
- "color": the primary color, in Polish (e.g. "granatowy", "ciemnozielony"), or null if unclear.
- "condition": one of the English values "new", "like new", "good", "fair", "worn", or null if unclear.
- "description": a short resale listing description (1–2 sentences), in Polish, or null if you cannot generate a confident one.

Rules:
- "color" and "description" MUST be in Polish. "category" and "condition" MUST be exactly one of the listed English values (lowercase) — do not invent or translate other words.
- Return ONLY a valid JSON object — no markdown, no code fences, no extra text.
- Return null for any field you cannot determine with high confidence. NEVER guess or fabricate a value.
PROMPT;
    }
}
