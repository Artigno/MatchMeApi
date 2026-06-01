<?php

namespace Tests\Feature;

use App\Contracts\GarmentClassifier;
use App\Models\Garment;
use App\Models\User;
use App\Testing\FakeGarmentClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GarmentClassifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_classify_creates_garment_and_returns_resource(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier());

        $user = User::factory()->create();
        $token = $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;

        $response = $this->postJson('/api/garments', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertOk()
            ->assertJsonStructure(['id', 'category', 'brand', 'color', 'condition', 'description', 'photo_url', 'created_at'])
            ->assertJsonFragment([
                'category'  => 'top',
                'brand'     => 'Zara',
                'color'     => 'blue',
                'condition' => 'good',
            ]);

        $this->assertDatabaseHas('garments', ['user_id' => $user->id, 'category' => 'top']);
    }

    public function test_classify_returns_null_fields_when_ai_uncertain(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier([
            'category'    => null,
            'brand'       => null,
            'color'       => null,
            'condition'   => null,
            'description' => null,
        ]));

        $user = User::factory()->create();
        $token = $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;

        $response = $this->postJson('/api/garments', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertOk()
            ->assertJsonFragment([
                'category'    => null,
                'brand'       => null,
                'color'       => null,
                'condition'   => null,
                'description' => null,
            ]);

        $this->assertSame(1, Garment::count());
    }

    public function test_classify_requires_authentication(): void
    {
        $this->postJson('/api/garments', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ])->assertUnauthorized();
    }

    public function test_classify_validates_photo_is_required(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier());

        $user = User::factory()->create();
        $token = $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;

        $this->postJson('/api/garments', [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_classify_returns_504_when_classifier_throws(): void
    {
        $this->app->instance(GarmentClassifier::class, new FakeGarmentClassifier(shouldThrow: true));

        $user = User::factory()->create();
        $token = $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;

        $this->postJson('/api/garments', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(504);

        $this->assertSame(0, Garment::count());
    }
}
