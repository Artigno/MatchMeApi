<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassifyController;
use App\Http\Controllers\Api\GarmentController;
use App\Http\Controllers\Api\SupabaseController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

Route::get('/up', fn () => response()->json(['status' => 'ok']));

// Unauthenticated AI classification, gated by the shared app key + throttle.
// Stateless: reads the photo, returns listing fields, persists nothing.
Route::post('/classify', [ClassifyController::class, 'classify'])
    ->middleware(['app.key', 'throttle:10,1']);

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

    Route::post('/garments', [GarmentController::class, 'store']);
    Route::get('/garments', [GarmentController::class, 'index']);
    Route::get('/garments/{garment}', [GarmentController::class, 'show']);
    Route::patch('/garments/{garment}', [GarmentController::class, 'update']);
    Route::delete('/garments/{garment}', [GarmentController::class, 'destroy']);
});
