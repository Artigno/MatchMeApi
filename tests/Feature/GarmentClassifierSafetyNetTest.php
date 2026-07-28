<?php

namespace Tests\Feature;

use App\Exceptions\ClassifierTimeoutException;
use App\Exceptions\ClassifierUpstreamException;
use App\Services\GarmentClassifierService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Direct unit coverage for the REAL parse/normalize path
 * (GarmentClassifierService::classify → extractFields → enumString/nullableString).
 *
 * The endpoint tests bind FakeGarmentClassifier and never execute this logic;
 * here we instantiate the real service and fake only the HTTP edge, so every
 * malformed AI shape is driven through the actual guardrail.
 *
 * Oracle discipline: each assertion checks the PRD rule (out-of-enum / garbage →
 * null; valid → the value), never a value copied from the parser's own output.
 */
class GarmentClassifierSafetyNetTest extends TestCase
{
    private const BASE_URL = 'https://openrouter.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openrouter.base_url' => self::BASE_URL,
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.model' => 'test/model',
        ]);
    }

    /**
     * Fake the OpenRouter endpoint so the model's message content is exactly $content.
     * $content is what the AI "returns" as text — usually a JSON string, possibly malformed.
     */
    private function fakeContent(mixed $content): void
    {
        Http::fake([
            self::BASE_URL.'/*' => Http::response([
                'choices' => [['message' => ['content' => $content]]],
            ]),
        ]);
    }

    private function classify(): array
    {
        return (new GarmentClassifierService)->classify('aGVsbG8=', 'image/jpeg');
    }

    // --- Happy path: valid values survive (lowercased where enum-guarded) ---

    public function test_valid_fields_survive(): void
    {
        $this->fakeContent(json_encode([
            'category' => 'footwear',
            'brand' => 'Nike',
            'color' => 'granatowy',
            'condition' => 'good',
            'description' => 'Wygodne buty w dobrym stanie.',
        ]));

        $this->assertSame([
            'category' => 'footwear',
            'brand' => 'Nike',
            'color' => 'granatowy',
            'condition' => 'good',
            'description' => 'Wygodne buty w dobrym stanie.',
        ], $this->classify());
    }

    // --- Shape 6: missing field → null ---

    public function test_missing_field_becomes_null(): void
    {
        $this->fakeContent(json_encode([
            'brand' => 'Nike',
            'color' => 'granatowy',
            // category, condition, description absent
        ]));

        $result = $this->classify();
        $this->assertNull($result['category']);
        $this->assertNull($result['condition']);
        $this->assertNull($result['description']);
    }

    // --- Shape 7: wrong-typed field → null ---

    public function test_wrong_typed_fields_become_null(): void
    {
        $this->fakeContent(json_encode([
            'category' => 123,
            'brand' => ['Nike', 'Adidas'],
            'color' => 42,
            'condition' => ['good'],
            'description' => (object) ['x' => 1],
        ]));

        $this->assertSame([
            'category' => null,
            'brand' => null,
            'color' => null,
            'condition' => null,
            'description' => null,
        ], $this->classify());
    }

    // --- Shape 8: condition valid string but out-of-enum → null ---

    public function test_out_of_enum_condition_becomes_null(): void
    {
        $this->fakeContent(json_encode(['condition' => 'doskonały']));

        $this->assertNull($this->classify()['condition']);
    }

    // --- Shape 9a: condition wrong case → lowercased value ---

    public function test_mixed_case_condition_is_lowercased(): void
    {
        $this->fakeContent(json_encode(['condition' => 'Good']));

        $this->assertSame('good', $this->classify()['condition']);
    }

    // --- Shape 9b: condition padded with whitespace → trimmed value (Phase 1 fix) ---

    public function test_whitespace_padded_condition_is_recovered(): void
    {
        $this->fakeContent(json_encode(['condition' => ' good ']));

        $this->assertSame('good', $this->classify()['condition']);
    }

    // --- Shape 10: empty string / literal "null" → null ---

    public function test_empty_and_literal_null_become_null(): void
    {
        $this->fakeContent(json_encode([
            'category' => '',
            'brand' => 'null',
            'color' => '',
            'condition' => 'null',
            'description' => '',
        ]));

        $this->assertSame([
            'category' => null,
            'brand' => null,
            'color' => null,
            'condition' => null,
            'description' => null,
        ], $this->classify());
    }

    // --- Shape 11: THE category leak — plausible out-of-enum category → null (Phase 1 fix) ---

    public function test_out_of_enum_category_does_not_leak(): void
    {
        // "sukienka" is plausible but outside the 5-value allow-list.
        $this->fakeContent(json_encode(['category' => 'sukienka']));

        $this->assertNull($this->classify()['category']);
    }

    public function test_valid_category_survives(): void
    {
        $this->fakeContent(json_encode(['category' => 'footwear']));

        $this->assertSame('footwear', $this->classify()['category']);
    }

    // --- Shapes 1–5: transport/parse failures raise typed exceptions at the service boundary ---

    public function test_connection_failure_raises_timeout_exception(): void
    {
        Http::fake([self::BASE_URL.'/*' => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(ClassifierTimeoutException::class);
        $this->classify();
    }

    public function test_non_2xx_status_raises_upstream_exception(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response([], 500)]);

        $this->expectException(ClassifierUpstreamException::class);
        $this->classify();
    }

    public function test_non_string_content_raises_upstream_exception(): void
    {
        $this->fakeContent(['not' => 'a string']);

        $this->expectException(ClassifierUpstreamException::class);
        $this->classify();
    }

    public function test_non_json_content_raises_upstream_exception(): void
    {
        $this->fakeContent('this is not json {');

        $this->expectException(ClassifierUpstreamException::class);
        $this->classify();
    }

    public function test_content_parsing_to_non_array_raises_upstream_exception(): void
    {
        // Valid JSON, but decodes to a scalar (not the expected object).
        $this->fakeContent('42');

        $this->expectException(ClassifierUpstreamException::class);
        $this->classify();
    }
}
