<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SupabaseJwtVerifier as SupabaseJwtVerifierContract;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

class SupabaseJwtVerifier implements SupabaseJwtVerifierContract
{
    public function verify(string $token): array
    {
        $secret = config('services.supabase.jwt_secret');

        if (empty($secret)) {
            throw new \RuntimeException('Supabase JWT secret is not configured.');
        }

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (ExpiredException $e) {
            throw new \RuntimeException('Token has expired.', 0, $e);
        } catch (SignatureInvalidException $e) {
            throw new \RuntimeException('Invalid token signature.', 0, $e);
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
}
