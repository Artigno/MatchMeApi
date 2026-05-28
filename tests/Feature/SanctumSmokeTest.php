<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanctumSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_access_token_returns_200(): void
    {
        $user = User::factory()->create(['name' => null]);
        $token = $user->createToken('access', ['access'], now()->addMinutes(5))->plainTextToken;

        $this->getJson('/api/ping', ['Authorization' => 'Bearer '.$token])
            ->assertOk();
    }

    public function test_expired_token_returns_401(): void
    {
        $user = User::factory()->create(['name' => null]);
        $token = $user->createToken('access', ['access'], now()->subMinute())->plainTextToken;

        $this->getJson('/api/ping', ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized();
    }

    public function test_refresh_token_rejected_on_business_routes(): void
    {
        $user = User::factory()->create(['name' => null]);
        $token = $user->createToken('refresh', ['refresh'], now()->addDays(30))->plainTextToken;

        $this->getJson('/api/ping', ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();
    }
}
