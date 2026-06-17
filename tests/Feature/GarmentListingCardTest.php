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

        $this->getJson("/api/garments/{$garment->id}", ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonStructure(['id', 'category', 'brand', 'color', 'condition', 'description', 'photo_url', 'created_at', 'updated_at'])
            ->assertJsonFragment(['category' => 'top', 'brand' => 'Zara']);
    }

    public function test_update_returns_updated_resource(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->patchJson("/api/garments/{$garment->id}", ['category' => 'bottom'], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonFragment(['category' => 'bottom', 'brand' => 'Zara', 'color' => 'blue']);

        $this->assertDatabaseHas('garments', ['id' => $garment->id, 'category' => 'bottom', 'brand' => 'Zara']);
    }

    public function test_update_ignores_brand(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->patchJson("/api/garments/{$garment->id}", ['brand' => 'Nike', 'color' => 'red'], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonFragment(['brand' => 'Zara', 'color' => 'red']);

        $this->assertDatabaseHas('garments', ['id' => $garment->id, 'brand' => 'Zara', 'color' => 'red']);
    }

    public function test_show_requires_authentication(): void
    {
        $user = User::factory()->create();
        $garment = $this->createGarment($user);

        $this->getJson("/api/garments/{$garment->id}")->assertUnauthorized();
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

        $this->getJson("/api/garments/{$garment->id}", ['Authorization' => 'Bearer '.$this->token($other)])
            ->assertNotFound();
    }

    public function test_update_returns_404_for_another_users_garment(): void
    {
        $owner = User::factory()->create();
        $garment = $this->createGarment($owner);

        $other = User::factory()->create();

        $this->patchJson("/api/garments/{$garment->id}", ['brand' => 'Nike'], ['Authorization' => 'Bearer '.$this->token($other)])
            ->assertNotFound();

        $this->assertDatabaseHas('garments', ['id' => $garment->id, 'brand' => 'Zara']);
    }
}
