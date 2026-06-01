<?php

namespace App\Providers;

use App\Contracts\GarmentClassifier;
use App\Contracts\SupabaseJwtVerifier;
use App\Services\GarmentClassifierService;
use App\Services\SupabaseJwtVerifier as SupabaseJwtVerifierService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupabaseJwtVerifier::class, SupabaseJwtVerifierService::class);
        $this->app->bind(GarmentClassifier::class, GarmentClassifierService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
