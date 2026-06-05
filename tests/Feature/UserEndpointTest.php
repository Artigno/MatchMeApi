<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_authenticated_user(): void
    {
        $user = User::factory()->create(['name' => 'Test User']);
        $token = $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;

        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonStructure(['id', 'email', 'name', 'created_at'])
            ->assertJsonFragment(['email' => $user->email, 'name' => 'Test User']);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_refresh_token_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('refresh', ['refresh'], now()->addDays(30))->plainTextToken;

        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();
    }
}
