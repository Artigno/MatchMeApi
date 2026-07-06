<?php

namespace Tests\Feature;

use App\Models\Garment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GarmentListingCardTest extends TestCase
{
    use RefreshDatabase;

    private function createGarment(User $user, array $fields = []): Garment
    {
        return Garment::factory()->for($user)->create(array_merge([
            'category' => 'top',
            'brand' => 'Zara',
            'color' => 'blue',
            'condition' => 'dobry',
            'description' => 'A nice top',
        ], $fields));
    }

    private function token(User $user): string
    {
        return $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;
    }

    public function test_show_returns_garment_resource(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->getJson("/api/garments/{$garment->getKey()}", ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonStructure(['id', 'category', 'brand', 'color', 'condition', 'description', 'photo_url', 'created_at', 'updated_at'])
            ->assertJsonFragment(['category' => 'top', 'brand' => 'Zara']);
    }

    public function test_update_returns_updated_resource(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->patchJson("/api/garments/{$garment->getKey()}", ['category' => 'bottom'], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonFragment(['category' => 'bottom', 'brand' => 'Zara', 'color' => 'blue']);

        $this->assertDatabaseHas('garments', ['id' => $garment->getKey(), 'category' => 'bottom', 'brand' => 'Zara']);
    }

    public function test_update_accepts_brand(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->patchJson("/api/garments/{$garment->getKey()}", ['brand' => 'Nike', 'color' => 'red'], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonFragment(['brand' => 'Nike', 'color' => 'red']);

        $this->assertDatabaseHas('garments', ['id' => $garment->getKey(), 'brand' => 'Nike', 'color' => 'red']);
    }

    public function test_update_clears_brand_with_null(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->patchJson("/api/garments/{$garment->getKey()}", ['brand' => null], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonFragment(['brand' => null]);

        $this->assertDatabaseHas('garments', ['id' => $garment->getKey(), 'brand' => null]);
    }

    public function test_update_with_current_if_unmodified_since_succeeds(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->patchJson("/api/garments/{$garment->getKey()}", ['color' => 'red'], [
            'Authorization' => 'Bearer '.$this->token($user),
            'If-Unmodified-Since' => $garment->updated_at->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonFragment(['color' => 'red']);
    }

    public function test_update_with_stale_if_unmodified_since_returns_409(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);
        $staleTimestamp = $garment->updated_at->toIso8601String();

        // Another device writes in between.
        $this->travel(1)->minutes();
        $garment->update(['color' => 'green']);

        $this->patchJson("/api/garments/{$garment->getKey()}", ['color' => 'red'], [
            'Authorization' => 'Bearer '.$this->token($user),
            'If-Unmodified-Since' => $staleTimestamp,
        ])
            ->assertStatus(409)
            ->assertJsonPath('garment.color', 'green');

        $this->assertDatabaseHas('garments', ['id' => $garment->getKey(), 'color' => 'green']);
    }

    public function test_update_with_invalid_if_unmodified_since_returns_400(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->patchJson("/api/garments/{$garment->getKey()}", ['color' => 'red'], [
            'Authorization' => 'Bearer '.$this->token($user),
            'If-Unmodified-Since' => 'not-a-date',
        ])
            ->assertStatus(400);

        $this->assertDatabaseHas('garments', ['id' => $garment->getKey(), 'color' => 'blue']);
    }

    public function test_show_requires_authentication(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->getJson("/api/garments/{$garment->getKey()}")->assertUnauthorized();
    }

    public function test_show_returns_404_for_unknown_garment(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/garments/99999', ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertNotFound();
    }

    public function test_show_returns_404_for_another_users_garment(): void
    {
        $owner = User::factory()->create();
        $garment = $this->createGarment($owner);

        $other = User::factory()->create();

        $this->getJson("/api/garments/{$garment->getKey()}", ['Authorization' => 'Bearer '.$this->token($other)])
            ->assertNotFound();
    }

    public function test_update_returns_404_for_another_users_garment(): void
    {
        $owner = User::factory()->create();
        $garment = $this->createGarment($owner);

        $other = User::factory()->create();

        $this->patchJson("/api/garments/{$garment->getKey()}", ['brand' => 'Nike'], ['Authorization' => 'Bearer '.$this->token($other)])
            ->assertNotFound();

        $this->assertDatabaseHas('garments', ['id' => $garment->getKey(), 'brand' => 'Zara']);
    }
}
