<?php

declare(strict_types=1);

namespace App\Contracts;

interface SupabaseJwtVerifier
{
    /**
     * Verify a Supabase JWT and return its claims.
     *
     * @return array{sub: string, email: string|null}
     *
     * @throws \RuntimeException on invalid signature, expiry, or malformed token
     */
    public function verify(string $token): array;
}
