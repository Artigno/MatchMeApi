<?php

namespace Tests\Feature;

use App\Models\Garment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GarmentPhotoReplacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function createGarmentWithPhoto(User $user): Garment
    {
        $garment = Garment::factory()->for($user)->create([
            'category' => 'top',
            'brand' => 'Zara',
            'color' => 'blue',
            'condition' => 'good',
            'description' => 'A nice top',
        ]);

        $garment->addMedia(UploadedFile::fake()->image('original.jpg'))->toMediaCollection('photos');

        return $garment->refresh();
    }

    private function token(User $user): string
    {
        return $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;
    }

    public function test_replace_photo_swaps_media_and_returns_resource(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarmentWithPhoto($user);
        $originalMediaId = $garment->getFirstMedia('photos')->getKey();

        $response = $this->postJson("/api/garments/{$garment->getKey()}/photo", [
            'photo' => UploadedFile::fake()->image('retake.jpg'),
        ], ['Authorization' => 'Bearer '.$this->token($user)]);

        $response->assertOk()
            ->assertJsonStructure(['id', 'category', 'brand', 'color', 'condition', 'description', 'photo_url', 'created_at', 'updated_at'])
            ->assertJsonFragment(['id' => $garment->getKey()]);

        $this->assertNotEmpty($response->json('photo_url'));

        // singleFile collection: exactly one media row, and it is a new one.
        $garment->refresh();
        $this->assertCount(1, $garment->getMedia('photos'));
        $this->assertNotSame($originalMediaId, $garment->getFirstMedia('photos')->getKey());
    }

    public function test_replace_photo_requires_photo(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarmentWithPhoto($user);

        $this->postJson("/api/garments/{$garment->getKey()}/photo", [], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_replace_photo_rejects_oversized_photo(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarmentWithPhoto($user);

        $this->postJson("/api/garments/{$garment->getKey()}/photo", [
            'photo' => UploadedFile::fake()->image('big.jpg')->size(20480),
        ], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_replace_photo_validation_returns_422_without_accept_header(): void
    {
        // Raw multipart POST (no Accept: application/json). The force-JSON API
        // middleware must keep this a 422, not a 302 redirect.
        $user = User::factory()->create();
        $garment = $this->createGarmentWithPhoto($user);
        $originalMediaId = $garment->getFirstMedia('photos')->getKey();

        $this->post("/api/garments/{$garment->getKey()}/photo", [], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertStatus(422);

        $garment->refresh();
        $this->assertSame($originalMediaId, $garment->getFirstMedia('photos')->getKey());
    }

    public function test_replace_photo_requires_authentication(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarmentWithPhoto($user);

        $this->postJson("/api/garments/{$garment->getKey()}/photo", [
            'photo' => UploadedFile::fake()->image('retake.jpg'),
        ])->assertUnauthorized();
    }

    public function test_replace_photo_returns_404_for_another_users_garment(): void
    {
        $owner = User::factory()->create();
        $garment = $this->createGarmentWithPhoto($owner);
        $originalMediaId = $garment->getFirstMedia('photos')->getKey();

        $other = User::factory()->create();

        $this->postJson("/api/garments/{$garment->getKey()}/photo", [
            'photo' => UploadedFile::fake()->image('retake.jpg'),
        ], ['Authorization' => 'Bearer '.$this->token($other)])
            ->assertNotFound();

        // The 404 must be a hard stop: a non-owner's attempt must not swap the
        // owner's photo before the ownership check fails.
        $garment->refresh();
        $this->assertCount(1, $garment->getMedia('photos'));
        $this->assertSame($originalMediaId, $garment->getFirstMedia('photos')->getKey());
    }
}
