<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\IssuesTokenPairs;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use IssuesTokenPairs;

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

        return DB::transaction(function () use ($user) {
            $user->currentAccessToken()->delete();

            return response()->json($this->issueTokenPair($user), 200);
        });
    }

    public function logout(Request $request): JsonResponse
    {
        if (! $request->user()->tokenCan('access')) {
            return response()->json(['message' => 'Invalid token type.'], 403);
        }

        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out.'], 200);
    }
}
