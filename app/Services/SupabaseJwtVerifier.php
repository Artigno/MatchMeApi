<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SupabaseJwtVerifier as SupabaseJwtVerifierContract;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SupabaseJwtVerifier implements SupabaseJwtVerifierContract
{
    private const CACHE_KEY = 'supabase_jwks';

    private const CACHE_TTL = 3600;

    public function verify(string $token): array
    {
        try {
            $decoded = JWT::decode($token, $this->signingKeys());
        } catch (ExpiredException $e) {
            throw new \RuntimeException('Token has expired.', 0, $e);
        } catch (SignatureInvalidException $e) {
            throw new \RuntimeException('Invalid token signature.', 0, $e);
        } catch (\UnexpectedValueException $e) {
            // Unknown `kid` usually means the signing keys rotated since we
            // cached them. Drop the cache and retry once against fresh keys.
            Cache::forget(self::CACHE_KEY);

            try {
                $decoded = JWT::decode($token, $this->signingKeys());
            } catch (\Exception $retry) {
                throw new \RuntimeException('Malformed or invalid token.', 0, $retry);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('Malformed or invalid token.', 0, $e);
        }

        if (empty($decoded->sub)) {
            throw new \RuntimeException('Token missing required sub claim.');
        }

        return [
            'sub' => $decoded->sub,
            'email' => $decoded->email ?? null,
        ];
    }

    /**
     * Supabase signing keys (ES256/RS256) from the project JWKS endpoint,
     * parsed into a kid-keyed map for JWT::decode. Raw JWKS is cached; the
     * parsed Key objects are rebuilt each call (they are not serializable).
     *
     * @return array<string, Key>
     */
    private function signingKeys(): array
    {
        $jwks = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->fetchJwks());

        return JWK::parseKeySet($jwks);
    }

    /**
     * @return array{keys: array<int, array<string, mixed>>}
     */
    private function fetchJwks(): array
    {
        $base = config('services.supabase.url');

        if (empty($base)) {
            throw new \RuntimeException('Supabase URL is not configured.');
        }

        $url = rtrim($base, '/').'/auth/v1/.well-known/jwks.json';

        $response = Http::timeout(5)->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch Supabase JWKS.');
        }

        return $response->json();
    }
}
