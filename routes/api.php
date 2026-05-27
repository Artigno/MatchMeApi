<?php

use Illuminate\Support\Facades\Route;

Route::get('/up', fn () => response()->json(['status' => 'ok']));

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/ping', fn () => response()->json(['status' => 'ok', 'user_id' => auth()->id()]));

    // S-01: account endpoints here
    // S-02: ai-classification endpoints here
    // S-03: listing-card-edit endpoints here
    // S-04: wardrobe-catalogue endpoints here
    // S-05: garment-removal endpoints here
});
