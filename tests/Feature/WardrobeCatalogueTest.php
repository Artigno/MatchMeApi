<?php

namespace Tests\Feature;

use App\Models\Garment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WardrobeCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private function createGarment(User $user, array $fields = []): Garment
    {
        return Garment::factory()->for($user)->create(array_merge([
            'category'    => 'top',
            'brand'       => 'Zara',
            'color'       => 'blue',
            'condition'   => 'good',
            'description' => 'A nice top',
        ], $fields));
    }

    private function token(User $user): string
    {
        return $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;
    }

    public function test_index_returns_paginated_garments(): void
    {
        $user = User::factory()->create();
        $this->createGarment($user, ['brand' => 'Oldest', 'created_at' => now()->subDays(2)]);
        $this->createGarment($user, ['brand' => 'Middle', 'created_at' => now()->subDay()]);
        $this->createGarment($user, ['brand' => 'Newest', 'created_at' => now()]);

        $response = $this->getJson('/api/garments', ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'category', 'brand', 'color', 'condition', 'description', 'photo_url', 'created_at', 'updated_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links' => ['first', 'last', 'prev', 'next'],
            ])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 20);

        $this->assertSame('Newest', $response->json('data.0.brand'));
    }

    public function test_index_returns_empty_data_for_empty_wardrobe(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/garments', ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/garments')->assertUnauthorized();
    }

    public function test_index_does_not_return_other_users_garments(): void
    {
        $owner = User::factory()->create();
        $this->createGarment($owner);
        $this->createGarment($owner);

        $other = User::factory()->create();
        $this->createGarment($other, ['brand' => 'OnlyMine']);

        $this->getJson('/api/garments', ['Authorization' => 'Bearer '.$this->token($other)])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.brand', 'OnlyMine');
    }
}
