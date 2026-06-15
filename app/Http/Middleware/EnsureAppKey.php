<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an endpoint to callers that present the shared application key in the
 * X-App-Key header. This is a coarse "only the app may call this" guard for
 * unauthenticated endpoints — not per-user auth.
 */
class EnsureAppKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.app.client_key');
        $provided = $request->header('X-App-Key');

        // Reject when the server key is unconfigured/blank so a missing or empty
        // env var can never collapse into an "empty == empty" bypass.
        if (! is_string($expected) || $expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
