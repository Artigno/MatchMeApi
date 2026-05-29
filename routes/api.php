<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SupabaseController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

Route::get('/up', fn () => response()->json(['status' => 'ok']));

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('supabase/exchange', [SupabaseController::class, 'exchange'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', CheckForAnyAbility::class.':access'])->group(function () {
    Route::get('/ping', fn () => response()->json(['status' => 'ok', 'user_id' => auth()->id()]));

    // S-02: ai-classification endpoints here
    // S-03: listing-card-edit endpoints here
    // S-04: wardrobe-catalogue endpoints here
    // S-05: garment-removal endpoints here
});
