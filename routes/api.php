<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GarmentController;
use App\Http\Controllers\Api\SupabaseController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

Route::get('/up', fn () => response()->json(['status' => 'ok']));

Route::prefix('auth')->group(function () {
    Route::post('supabase/exchange', [SupabaseController::class, 'exchange'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', CheckForAnyAbility::class.':access'])->group(function () {
    Route::get('/user', [UserController::class, 'show']);
    Route::get('/ping', fn () => response()->json(['status' => 'ok', 'user_id' => auth()->id()]));

    Route::post('/garments', [GarmentController::class, 'classify']);
    Route::get('/garments/{garment}', [GarmentController::class, 'show']);
    Route::patch('/garments/{garment}', [GarmentController::class, 'update']);
    // S-04: wardrobe-catalogue endpoints here
    // S-05: garment-removal endpoints here
});
