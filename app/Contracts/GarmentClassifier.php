<?php

declare(strict_types=1);

namespace App\Contracts;

interface GarmentClassifier
{
    /**
     * Classify a garment photo and return listing card fields.
     *
     * @return array{category: string|null, brand: string|null, color: string|null, condition: string|null, description: string|null}
     *
     * @throws \RuntimeException on API failure, timeout, or unexpected response shape
     */
    public function classify(string $base64Image, string $mimeType): array;
}
