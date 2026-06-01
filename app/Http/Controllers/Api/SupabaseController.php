<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\SupabaseJwtVerifier;
use App\Http\Controllers\Api\Concerns\IssuesTokenPairs;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupabaseController extends Controller
{
    use IssuesTokenPairs;

    public function __construct(private readonly SupabaseJwtVerifier $verifier) {}

    public function exchange(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return response()->json(['message' => 'Token required.'], 401);
        }

        try {
            $claims = $this->verifier->verify($token);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'Invalid or expired token.'], 401);
        }

        if ($claims['email'] === null) {
            return response()->json(['message' => 'Email claim required.'], 422);
        }

        try {
            return DB::transaction(function () use ($claims) {
                $user = User::firstOrCreate(
                    ['supabase_id' => $claims['sub']],
                    ['email' => $claims['email'], 'password' => bcrypt(str()->random(40))],
                );
                if ($user->email !== $claims['email']) {
                    $user->email = $claims['email'];
                    $user->save();
                }

                return response()->json($this->issueTokenPair($user), 200);
            });
        } catch (\Illuminate\Database\QueryException) {
            return response()->json(['message' => 'Email already in use.'], 409);
        }
    }
}
