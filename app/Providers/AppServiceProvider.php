<?php

namespace App\Providers;

use App\Contracts\GarmentClassifier;
use App\Contracts\SupabaseJwtVerifier;
use App\Services\GarmentClassifierService;
use App\Services\SupabaseJwtVerifier as SupabaseJwtVerifierService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupabaseJwtVerifier::class, SupabaseJwtVerifierService::class);
        $this->app->bind(GarmentClassifier::class, GarmentClassifierService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /classify is unauthenticated and calls a paid AI provider. The static
        // X-App-Key is extractable from the app binary and the per-IP key is
        // spoofable via X-Forwarded-For behind trustProxies(*), so the global
        // cap is the real ceiling on AI spend.
        RateLimiter::for('classify', fn (Request $request) => [
            // Best-effort per client — slows casual abuse, not the real guard.
            Limit::perMinute(10)->by((string) $request->ip()),
            // Hard ceiling on total AI spend — immune to IP/XFF rotation.
            Limit::perMinute(100)->by('classify-global'),
        ]);
    }
}
