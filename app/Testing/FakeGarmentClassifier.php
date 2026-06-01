<?php

declare(strict_types=1);

namespace App\Testing;

use App\Contracts\GarmentClassifier;

class FakeGarmentClassifier implements GarmentClassifier
{
    public function __construct(
        private readonly ?array $result = null,
        private readonly bool $shouldThrow = false,
    ) {}

    public function classify(string $base64Image, string $mimeType): array
    {
        if ($this->shouldThrow) {
            throw new \RuntimeException('Classifier failed.');
        }

        return $this->result ?? [
            'category'    => 'top',
            'brand'       => 'Zara',
            'color'       => 'blue',
            'condition'   => 'good',
            'description' => 'A nice blue top in good condition.',
        ];
    }
}
