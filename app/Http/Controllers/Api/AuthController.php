<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const ACCESS_TTL_MINUTES = 15;

    private const REFRESH_TTL_DAYS = 30;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        return response()->json($this->issueTokenPair($user), 200);
    }

    public function refresh(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->tokenCan('refresh')) {
            return response()->json(['message' => 'Invalid token type.'], 403);
        }

        $user->currentAccessToken()->delete();

        return response()->json($this->issueTokenPair($user), 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out.'], 200);
    }

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
