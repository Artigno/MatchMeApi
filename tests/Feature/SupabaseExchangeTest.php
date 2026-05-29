<?php

namespace Tests\Feature;

use App\Contracts\SupabaseJwtVerifier;
use App\Models\User;
use App\Testing\FakeSupabaseJwtVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupabaseExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_creates_new_user_and_returns_token_pair(): void
    {
        $this->app->instance(SupabaseJwtVerifier::class, new FakeSupabaseJwtVerifier([
            'sub' => 'uuid-new-user',
            'email' => 'new@supabase.local',
        ]));

        $response = $this->postJson('/api/auth/supabase/exchange', [], [
            'Authorization' => 'Bearer fake-token',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);

        $this->assertDatabaseHas('users', [
            'supabase_id' => 'uuid-new-user',
            'email' => 'new@supabase.local',
        ]);
    }

    public function test_exchange_finds_existing_user_by_supabase_id(): void
    {
        $existing = User::factory()->create([
            'supabase_id' => 'uuid-existing',
            'email' => 'existing@supabase.local',
        ]);

        $this->app->instance(SupabaseJwtVerifier::class, new FakeSupabaseJwtVerifier([
            'sub' => 'uuid-existing',
            'email' => 'existing@supabase.local',
        ]));

        $response = $this->postJson('/api/auth/supabase/exchange', [], [
            'Authorization' => 'Bearer fake-token',
        ]);

        $response->assertOk();

        $this->assertSame($existing->id, User::where('supabase_id', 'uuid-existing')->sole()->id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_exchange_without_token_returns_401(): void
    {
        $this->postJson('/api/auth/supabase/exchange')
            ->assertUnauthorized();
    }

    public function test_exchange_with_invalid_jwt_returns_401(): void
    {
        $this->app->instance(SupabaseJwtVerifier::class, new FakeSupabaseJwtVerifier(
            throwMessage: 'Token has expired.'
        ));

        $this->postJson('/api/auth/supabase/exchange', [], [
            'Authorization' => 'Bearer bad-token',
        ])->assertUnauthorized();
    }
}
