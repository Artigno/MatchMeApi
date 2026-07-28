<?php

namespace Tests\Feature;

use App\Contracts\GarmentClassifier;
use App\Exceptions\ClassifierTimeoutException;
use App\Exceptions\ClassifierUpstreamException;
use App\Models\Garment;
use App\Testing\FakeGarmentClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClassifyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const APP_KEY = 'test-app-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.app.client_key' => self::APP_KEY]);
    }

    private function appKeyHeader(): array
    {
        return ['X-App-Key' => self::APP_KEY];
    }

    public function test_classify_returns_fields_and_persists_nothing(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier);

        $response = $this->postJson('/api/classify', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], $this->appKeyHeader());

        $response->assertOk()
            ->assertExactJson([
                'category' => 'tops',
                'brand' => 'Zara',
                'color' => 'niebieski',
                'condition' => 'good',
                'description' => 'Ładny niebieski top w dobrym stanie.',
            ]);

        $this->assertSame(0, Garment::count());
    }

    public function test_classify_returns_null_fields_when_ai_uncertain(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier([
            'category' => null,
            'brand' => null,
            'color' => null,
            'condition' => null,
            'description' => null,
        ]));

        $this->postJson('/api/classify', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], $this->appKeyHeader())
            ->assertOk()
            ->assertJson(['category' => null, 'brand' => null, 'condition' => null]);
    }

    public function test_classify_rejects_missing_app_key(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier);

        $this->postJson('/api/classify', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ])->assertForbidden();
    }

    public function test_classify_rejects_wrong_app_key(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier);

        $this->postJson('/api/classify', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], ['X-App-Key' => 'wrong'])->assertForbidden();
    }

    public function test_classify_rejects_when_server_key_blank(): void
    {
        config(['services.app.client_key' => '']);
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier);

        $this->postJson('/api/classify', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], ['X-App-Key' => ''])->assertForbidden();
    }

    public function test_classify_validates_photo_required(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier);

        $this->postJson('/api/classify', [], $this->appKeyHeader())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_classify_validation_returns_422_without_accept_header(): void
    {
        // Raw multipart POST (no Accept: application/json). The force-JSON API
        // middleware must keep this a 422, not a 302 redirect.
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier);

        $this->post('/api/classify', [], $this->appKeyHeader())
            ->assertStatus(422);
    }

    public function test_classify_returns_504_on_timeout(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier(
            throw: new ClassifierTimeoutException('timed out'),
        ));

        $this->postJson('/api/classify', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], $this->appKeyHeader())->assertStatus(504);
    }

    public function test_classify_returns_502_on_upstream_failure(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier(
            throw: new ClassifierUpstreamException('bad gateway'),
        ));

        $this->postJson('/api/classify', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], $this->appKeyHeader())->assertStatus(502);
    }
}
