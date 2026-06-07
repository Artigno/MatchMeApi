<?php

namespace Tests\Feature;

use App\Services\SupabaseJwtVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseJwtVerifierTest extends TestCase
{
    private const KID = 'test-key-1';

    private const JWKS_URL = 'https://proj.supabase.co/auth/v1/.well-known/jwks.json';

    // Static ES256 (P-256) test keypair — generated once via the openssl CLI so the
    // suite never depends on runtime key generation (openssl_pkey_new is environment
    // sensitive). The public JWK below (x/y) corresponds to this private key.
    private const PRIVATE_PEM = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIJIFkYzCTbxHKO9yScz3W5rQ3c0Ci8IpXPUUqk6OVfWjoAoGCCqGSM49
AwEHoUQDQgAE47cQhNJPvOt9L3F/Plw05wFqQcOAftIzDg8dsES395EcLg64yyqJ
AifCVVFFpxZLpllAAmToyaZ9Ty1p/I9aDw==
-----END EC PRIVATE KEY-----
PEM;

    private const JWK_X = '47cQhNJPvOt9L3F_Plw05wFqQcOAftIzDg8dsES395E';

    private const JWK_Y = 'HC4OuMsqiQInwlVRRacWS6ZZQAJk6MmmfU8tafyPWg8';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.supabase.url' => 'https://proj.supabase.co']);
    }

    private function jwk(string $x = self::JWK_X, string $y = self::JWK_Y): array
    {
        return ['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig', 'kid' => self::KID, 'x' => $x, 'y' => $y];
    }

    private function fakeJwks(?array $jwk = null): void
    {
        Http::fake([self::JWKS_URL => Http::response(['keys' => [$jwk ?? $this->jwk()]])]);
    }

    private function sign(array $claims): string
    {
        return JWT::encode($claims, self::PRIVATE_PEM, 'ES256', self::KID);
    }

    public function test_verifies_a_valid_es256_token_and_returns_claims(): void
    {
        $this->fakeJwks();

        $claims = (new SupabaseJwtVerifier)->verify($this->sign([
            'sub' => 'user-123',
            'email' => 'user@example.com',
            'exp' => time() + 3600,
        ]));

        $this->assertSame('user-123', $claims['sub']);
        $this->assertSame('user@example.com', $claims['email']);
    }

    public function test_email_is_null_when_claim_absent(): void
    {
        $this->fakeJwks();

        $claims = (new SupabaseJwtVerifier)->verify($this->sign([
            'sub' => 'user-123',
            'exp' => time() + 3600,
        ]));

        $this->assertNull($claims['email']);
    }

    public function test_rejects_an_expired_token(): void
    {
        $this->fakeJwks();

        $this->expectException(\RuntimeException::class);

        (new SupabaseJwtVerifier)->verify($this->sign([
            'sub' => 'user-123',
            'email' => 'user@example.com',
            'exp' => time() - 10,
        ]));
    }

    public function test_rejects_a_token_signed_by_a_different_key(): void
    {
        // JWKS advertises a different public key than the one that signed the token.
        $this->fakeJwks($this->jwk(
            'cw8vmJkDVfpckCXZJv-oxFilm7A5HU7WdKIC8zrQQvI',
            'WakRYBa5y9_GwlWp59Wrx8GszzthY-eppKXH5aJMv44',
        ));

        $this->expectException(\RuntimeException::class);

        (new SupabaseJwtVerifier)->verify($this->sign([
            'sub' => 'user-123',
            'email' => 'user@example.com',
            'exp' => time() + 3600,
        ]));
    }

    public function test_throws_when_supabase_url_not_configured(): void
    {
        config(['services.supabase.url' => null]);

        $this->expectException(\RuntimeException::class);

        (new SupabaseJwtVerifier)->verify($this->sign([
            'sub' => 'user-123',
            'exp' => time() + 3600,
        ]));
    }
}
