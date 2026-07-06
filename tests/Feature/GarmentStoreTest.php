<?php

namespace Tests\Feature;

use App\Models\Garment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GarmentStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function token(User $user): string
    {
        return $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;
    }

    public function test_store_persists_garment_and_returns_resource(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/garments', [
            'category' => 'top',
            'brand' => 'Zara',
            'color' => 'blue',
            'condition' => 'dobry',
            'description' => 'A blue cotton top.',
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], ['Authorization' => 'Bearer '.$this->token($user)]);

        $response->assertOk()
            ->assertJsonStructure(['id', 'category', 'brand', 'color', 'condition', 'description', 'photo_url', 'created_at'])
            ->assertJsonFragment([
                'category' => 'top',
                'brand' => 'Zara',
                'color' => 'blue',
                'condition' => 'dobry',
            ]);

        $this->assertNotEmpty($response->json('photo_url'));

        $this->assertDatabaseHas('garments', [
            'user_id' => $user->getKey(),
            'category' => 'top',
            'brand' => 'Zara',
        ]);
    }

    public function test_store_accepts_null_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/garments', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], ['Authorization' => 'Bearer '.$this->token($user)]);

        $response->assertOk()
            ->assertJsonFragment([
                'category' => null,
                'brand' => null,
                'color' => null,
                'condition' => null,
                'description' => null,
            ]);

        $this->assertSame(1, Garment::count());
    }

    public function test_store_persists_client_ref_and_returns_it(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/garments', [
            'client_ref' => 'local-abc-123',
            'category' => 'top',
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], ['Authorization' => 'Bearer '.$this->token($user)]);

        $response->assertOk()
            ->assertJsonFragment(['client_ref' => 'local-abc-123']);

        $this->assertDatabaseHas('garments', [
            'user_id' => $user->getKey(),
            'client_ref' => 'local-abc-123',
        ]);
    }

    public function test_store_with_duplicate_client_ref_returns_existing_row(): void
    {
        // Idempotent create: a retried POST (response lost) must not duplicate the row.
        $user = User::factory()->create();
        $auth = ['Authorization' => 'Bearer '.$this->token($user)];

        $first = $this->postJson('/api/garments', [
            'client_ref' => 'local-abc-123',
            'category' => 'top',
            'brand' => 'Zara',
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], $auth);

        $second = $this->postJson('/api/garments', [
            'client_ref' => 'local-abc-123',
            'category' => 'bottom',
            'photo' => UploadedFile::fake()->image('retry.jpg'),
        ], $auth);

        $second->assertOk()
            ->assertJsonFragment([
                'id' => $first->json('id'),
                'category' => 'top',
                'brand' => 'Zara',
            ]);

        $this->assertSame(1, Garment::count());
    }

    public function test_store_allows_same_client_ref_for_different_users(): void
    {
        foreach (User::factory()->count(2)->create() as $user) {
            // Drop the guard's cached user so each request authenticates as its own user.
            $this->app->make('auth')->forgetGuards();

            $this->postJson('/api/garments', [
                'client_ref' => 'local-abc-123',
                'photo' => UploadedFile::fake()->image('garment.jpg'),
            ], ['Authorization' => 'Bearer '.$this->token($user)])->assertOk();
        }

        $this->assertSame(2, Garment::count());
    }

    public function test_store_without_client_ref_always_creates(): void
    {
        $user = User::factory()->create();
        $auth = ['Authorization' => 'Bearer '.$this->token($user)];

        foreach (range(1, 2) as $i) {
            $this->postJson('/api/garments', [
                'photo' => UploadedFile::fake()->image("garment-{$i}.jpg"),
            ], $auth)->assertOk();
        }

        $this->assertSame(2, Garment::count());
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/garments', [
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ])->assertUnauthorized();

        $this->assertSame(0, Garment::count());
    }

    public function test_store_requires_photo(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/garments', [], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_store_rejects_invalid_condition(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/garments', [
            'condition' => 'pristine',
            'photo' => UploadedFile::fake()->image('garment.jpg'),
        ], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['condition']);

        $this->assertSame(0, Garment::count());
    }

    public function test_store_rejects_oversized_photo(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/garments', [
            'photo' => UploadedFile::fake()->image('garment.jpg')->size(20480),
        ], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo']);

        $this->assertSame(0, Garment::count());
    }

    public function test_store_validation_returns_422_without_accept_header(): void
    {
        // Raw multipart POST (no Accept: application/json). The force-JSON API
        // middleware must keep this a 422, not a 302 redirect.
        $user = User::factory()->create();

        $this->post('/api/garments', [], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertStatus(422);

        $this->assertSame(0, Garment::count());
    }
}
