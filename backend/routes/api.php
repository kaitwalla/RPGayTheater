<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ControlAuthenticationController;
use App\Http\Controllers\Api\ControlCampaignController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->prefix('control/v1')->group(function (): void {
    Route::get('auth', [ControlAuthenticationController::class, 'show']);
    Route::post('auth/login', [ControlAuthenticationController::class, 'login'])->middleware('throttle:control-login');
    Route::post('auth/logout', [ControlAuthenticationController::class, 'logout'])->middleware('control');

    Route::middleware('control')->group(function (): void {
        Route::get('campaigns', [ControlCampaignController::class, 'index']);
        Route::post('campaigns', [ControlCampaignController::class, 'store']);
        Route::patch('campaigns/{campaign}', [ControlCampaignController::class, 'update']);
        Route::post('campaigns/{campaign}/publish', [ControlCampaignController::class, 'publish']);
        Route::delete('campaigns/{campaign}', [ControlCampaignController::class, 'destroy']);
    });
});
