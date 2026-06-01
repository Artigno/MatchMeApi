<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GarmentClassifier;
use Illuminate\Support\Facades\Http;

class GarmentClassifierService implements GarmentClassifier
{
    private const VALID_CONDITIONS = ['new', 'like new', 'good', 'fair', 'worn'];

    public function classify(string $base64Image, string $mimeType): array
    {
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
                            'text' => 'Analyze this garment and return the listing card fields.',
                        ],
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenRouter API returned '.$response->status());
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content)) {
            throw new \RuntimeException('Unexpected response shape from OpenRouter.');
        }

        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            throw new \RuntimeException('Could not parse JSON from OpenRouter response.');
        }

        return $this->extractFields($parsed);
    }

    private function extractFields(array $data): array
    {
        $condition = $this->nullableString($data['condition'] ?? null);

        return [
            'category'    => $this->nullableString($data['category'] ?? null),
            'brand'       => $this->nullableString($data['brand'] ?? null),
            'color'       => $this->nullableString($data['color'] ?? null),
            'condition'   => ($condition !== null && in_array(strtolower($condition), self::VALID_CONDITIONS, true))
                ? strtolower($condition)
                : null,
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

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a garment listing assistant. Analyze the provided garment photo and return a JSON object with exactly these five fields:

- "category": one of "top", "bottom", "shoes", "accessory", "outerwear", or null if unclear
- "brand": the brand name as a string (e.g. "Zara", "Nike"), or null if not clearly visible
- "color": the primary color as a string (e.g. "blue", "dark green"), or null if unclear
- "condition": one of "new", "like new", "good", "fair", "worn", or null if unclear
- "description": a short resale listing description (1–2 sentences), or null if you cannot generate a confident one

Rules:
- Return ONLY a valid JSON object — no markdown, no code fences, no extra text.
- Return null for any field you cannot determine with high confidence. NEVER guess or fabricate a value.
PROMPT;
    }
}
