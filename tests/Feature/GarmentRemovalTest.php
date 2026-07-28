<?php

namespace Tests\Feature;

use App\Models\Garment;
use App\Models\GarmentDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GarmentRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function createGarment(User $user, array $fields = []): Garment
    {
        return Garment::factory()->for($user)->create(array_merge([
            'category' => 'top',
            'brand' => 'Zara',
            'color' => 'blue',
            'condition' => 'good',
            'description' => 'A nice top',
        ], $fields));
    }

    private function withPhoto(Garment $garment): Garment
    {
        $garment->addMedia(UploadedFile::fake()->image('garment.jpg'))->toMediaCollection('photos');

        return $garment->refresh();
    }

    private function token(User $user): string
    {
        return $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;
    }

    public function test_owner_deletes_garment_removes_row_media_and_writes_audit(): void
    {
        $user = User::factory()->create();
        $garment = $this->withPhoto($this->createGarment($user));

        $this->assertDatabaseHas('media', ['model_id' => $garment->getKey(), 'model_type' => Garment::class]);

        $this->deleteJson("/api/garments/{$garment->getKey()}", [], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertNoContent();

        $this->assertDatabaseMissing('garments', ['id' => $garment->getKey()]);
        $this->assertDatabaseMissing('media', ['model_id' => $garment->getKey(), 'model_type' => Garment::class]);
        $this->assertDatabaseHas('garment_deletions', [
            'garment_id' => $garment->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_cannot_delete_another_users_garment(): void
    {
        $owner = User::factory()->create();
        $garment = $this->createGarment($owner);

        $other = User::factory()->create();

        $this->deleteJson("/api/garments/{$garment->getKey()}", [], ['Authorization' => 'Bearer '.$this->token($other)])
            ->assertNotFound();

        $this->assertDatabaseHas('garments', ['id' => $garment->getKey()]);
        $this->assertDatabaseMissing('garment_deletions', ['garment_id' => $garment->getKey()]);
    }

    public function test_delete_requires_authentication(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->deleteJson("/api/garments/{$garment->getKey()}")->assertUnauthorized();

        $this->assertDatabaseHas('garments', ['id' => $garment->getKey()]);
    }

    public function test_delete_unknown_garment_returns_404(): void
    {
        $user = User::factory()->create();

        $this->deleteJson('/api/garments/99999', [], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertNotFound();
    }

    public function test_redeleting_a_removed_garment_returns_204(): void
    {
        // Idempotent delete: a retry after a lost 204 must succeed, not loop on 404.
        $user = User::factory()->create();
        $garment = $this->createGarment($user);
        $auth = ['Authorization' => 'Bearer '.$this->token($user)];

        $this->deleteJson("/api/garments/{$garment->getKey()}", [], $auth)->assertNoContent();
        $this->deleteJson("/api/garments/{$garment->getKey()}", [], $auth)->assertNoContent();

        $this->assertSame(1, GarmentDeletion::where('garment_id', $garment->getKey())->count());
    }

    public function test_redeleting_another_users_removed_garment_returns_404(): void
    {
        // The tombstone belongs to the owner; another caller gets no idempotency signal.
        $owner = User::factory()->create();
        $garment = $this->createGarment($owner);

        $this->deleteJson("/api/garments/{$garment->getKey()}", [], ['Authorization' => 'Bearer '.$this->token($owner)])
            ->assertNoContent();

        $other = User::factory()->create();

        // Drop the guard's cached user so the second request authenticates as $other.
        $this->app->make('auth')->forgetGuards();

        $this->deleteJson("/api/garments/{$garment->getKey()}", [], ['Authorization' => 'Bearer '.$this->token($other)])
            ->assertNotFound();
    }
}
