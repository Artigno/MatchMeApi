<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_with_valid_refresh_token_returns_new_pair(): void
    {
        $user = User::factory()->create();
        $refreshToken = $user->createToken('refresh', ['refresh'], now()->addDays(30))->plainTextToken;

        $response = $this->postJson('/api/auth/refresh', [], [
            'Authorization' => 'Bearer '.$refreshToken,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);
    }

    public function test_refresh_rotates_token_old_refresh_token_is_revoked(): void
    {
        $user = User::factory()->create();
        $refreshToken = $user->createToken('refresh', ['refresh'], now()->addDays(30))->plainTextToken;
        $tokenId = (int) explode('|', $refreshToken)[0];

        $this->postJson('/api/auth/refresh', [], ['Authorization' => 'Bearer '.$refreshToken])
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertTrue($user->fresh()->tokens()->where('name', 'access')->exists());
        $this->assertTrue($user->fresh()->tokens()->where('name', 'refresh')->exists());
    }

    public function test_refresh_with_access_token_returns_403(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('access', ['access'], now()->addMinutes(15))->plainTextToken;

        $this->postJson('/api/auth/refresh', [], ['Authorization' => 'Bearer '.$accessToken])
            ->assertForbidden();
    }

    public function test_refresh_with_expired_token_returns_401(): void
    {
        $user = User::factory()->create();
        $expiredToken = $user->createToken('refresh', ['refresh'], now()->subMinute())->plainTextToken;

        $this->postJson('/api/auth/refresh', [], ['Authorization' => 'Bearer '.$expiredToken])
            ->assertUnauthorized();
    }

    public function test_logout_revokes_all_tokens(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('access', ['access'], now()->addMinutes(15))->plainTextToken;

        $this->postJson('/api/auth/logout', [], ['Authorization' => 'Bearer '.$accessToken])
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
