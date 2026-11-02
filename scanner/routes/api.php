<?php

use App\Http\Controllers\Api\ScanController;
use App\Http\Middleware\RequireApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([RequireApiKey::class, 'throttle:120,1'])->group(function () {
    Route::post('/scans', [ScanController::class, 'store']);
    Route::get('/scans/{id}', [ScanController::class, 'show']);
});
