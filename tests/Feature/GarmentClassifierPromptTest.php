<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Garment;
use App\Services\GarmentClassifierService;
use ReflectionMethod;
use Tests\TestCase;

class GarmentClassifierPromptTest extends TestCase
{
    private function systemPrompt(): string
    {
        $method = new ReflectionMethod(GarmentClassifierService::class, 'systemPrompt');

        return (string) $method->invoke(new GarmentClassifierService);
    }

    public function test_prompt_requests_polish_output(): void
    {
        $prompt = $this->systemPrompt();

        $this->assertStringContainsStringIgnoringCase('IN POLISH', $prompt);
    }

    public function test_prompt_lists_canonical_condition_values(): void
    {
        $prompt = $this->systemPrompt();

        // Every canonical condition value must appear verbatim so the model
        // returns values that pass Garment::CONDITIONS normalization.
        foreach (Garment::CONDITIONS as $value) {
            $this->assertStringContainsString('"'.$value.'"', $prompt);
        }
    }

    public function test_prompt_lists_canonical_category_values(): void
    {
        $prompt = $this->systemPrompt();

        foreach (Garment::CATEGORIES as $value) {
            $this->assertStringContainsString('"'.$value.'"', $prompt);
        }
    }
}
