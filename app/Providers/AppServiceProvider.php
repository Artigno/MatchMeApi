<?php

namespace App\Providers;

use App\Contracts\SupabaseJwtVerifier;
use App\Services\SupabaseJwtVerifier as SupabaseJwtVerifierService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupabaseJwtVerifier::class, SupabaseJwtVerifierService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
