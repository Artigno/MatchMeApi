<?php

declare(strict_types=1);

namespace App\Testing;

use App\Contracts\GarmentClassifier;

class FakeGarmentClassifier implements GarmentClassifier
{
    /**
     * @param  \Throwable|null  $throw  when set, classify() raises it (use the
     *                                  typed classifier exceptions to drive 504/502)
     */
    public function __construct(
        private readonly ?array $result = null,
        private readonly bool $shouldThrow = false,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function classify(string $base64Image, string $mimeType): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->shouldThrow) {
            throw new \RuntimeException('Classifier failed.');
        }

        return $this->result ?? [
            'category' => 'tops',
            'brand' => 'Zara',
            'color' => 'niebieski',
            'condition' => 'good',
            'description' => 'Ładny niebieski top w dobrym stanie.',
        ];
    }
}
