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
        $condition = $this->nullableString($data['condition'] ?? null);

        return [
            'category' => $this->nullableString($data['category'] ?? null),
            'brand' => $this->nullableString($data['brand'] ?? null),
            'color' => $this->nullableString($data['color'] ?? null),
            'condition' => ($condition !== null && in_array(mb_strtolower($condition, 'UTF-8'), Garment::CONDITIONS, true))
                ? mb_strtolower($condition, 'UTF-8')
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
You are a garment listing assistant for a Polish-speaking marketplace. Analyze the provided garment photo and return a JSON object with exactly these five fields, written IN POLISH (except "brand"):

- "category": one of the Polish values "góra", "dół", "buty", "akcesorium", "okrycie wierzchnie", or null if unclear.
- "brand": the brand name verbatim as printed (e.g. "Zara", "Nike"), or null if not clearly visible. Do NOT translate brand names.
- "color": the primary color, in Polish (e.g. "granatowy", "ciemnozielony"), or null if unclear.
- "condition": one of the Polish values "nowy", "jak nowy", "dobry", "średni", "znoszony", or null if unclear.
- "description": a short resale listing description (1–2 sentences), in Polish, or null if you cannot generate a confident one.

Rules:
- Every field except "brand" MUST be in Polish. "category" and "condition" MUST be exactly one of the listed Polish values (lowercase) — do not invent other words.
- Return ONLY a valid JSON object — no markdown, no code fences, no extra text.
- Return null for any field you cannot determine with high confidence. NEVER guess or fabricate a value.
PROMPT;
    }
}
