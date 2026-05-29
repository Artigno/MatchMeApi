<?php

declare(strict_types=1);

namespace App\Testing;

use App\Contracts\SupabaseJwtVerifier;

class FakeSupabaseJwtVerifier implements SupabaseJwtVerifier
{
    public function __construct(
        private readonly ?array $payload = null,
        private readonly ?string $throwMessage = null,
    ) {}

    public function verify(string $token): array
    {
        if ($this->throwMessage !== null) {
            throw new \RuntimeException($this->throwMessage);
        }

        return $this->payload ?? [
            'sub' => '00000000-0000-0000-0000-000000000001',
            'email' => 'test@supabase.local',
        ];
    }
}
