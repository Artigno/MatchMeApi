<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;

trait IssuesTokenPairs
{
    private const ACCESS_TTL_MINUTES = 15;

    private const REFRESH_TTL_DAYS = 30;

    private function issueTokenPair(User $user): array
    {
        $accessToken = $user->createToken(
            'access',
            ['access'],
            now()->addMinutes(self::ACCESS_TTL_MINUTES)
        );

        $refreshToken = $user->createToken(
            'refresh',
            ['refresh'],
            now()->addDays(self::REFRESH_TTL_DAYS)
        );

        return [
            'access_token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_MINUTES * 60,
        ];
    }
}
