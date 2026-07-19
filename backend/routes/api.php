<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ControlAssetController;
use App\Http\Controllers\Api\ControlAudioCueController;
use App\Http\Controllers\Api\ControlAuthenticationController;
use App\Http\Controllers\Api\ControlCampaignController;
use App\Http\Controllers\Api\ControlNpcController;
use App\Http\Controllers\Api\ControlPlayerCharacterController;
use App\Http\Controllers\Api\ControlSceneController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->prefix('control/v1')->group(function (): void {
    Route::get('auth', [ControlAuthenticationController::class, 'show']);
    Route::post('auth/login', [ControlAuthenticationController::class, 'login'])->middleware('throttle:control-login');
    Route::post('auth/logout', [ControlAuthenticationController::class, 'logout'])->middleware('control');

    Route::middleware('control')->group(function (): void {
        Route::get('campaigns', [ControlCampaignController::class, 'index']);
        Route::post('campaigns', [ControlCampaignController::class, 'store']);
        Route::get('campaigns/{campaign}/assets', [ControlAssetController::class, 'index']);
        Route::get('campaigns/{campaign}/audio-cues', [ControlAudioCueController::class, 'index']);
        Route::post('campaigns/{campaign}/audio-cues', [ControlAudioCueController::class, 'store']);
        Route::get('campaigns/{campaign}/scenes', [ControlSceneController::class, 'index']);
        Route::post('campaigns/{campaign}/scenes', [ControlSceneController::class, 'store']);
        Route::get('campaigns/{campaign}/player-characters', [ControlPlayerCharacterController::class, 'index']);
        Route::post('campaigns/{campaign}/player-characters', [ControlPlayerCharacterController::class, 'store']);
        Route::get('campaigns/{campaign}/npcs', [ControlNpcController::class, 'index']);
        Route::post('campaigns/{campaign}/npcs', [ControlNpcController::class, 'store']);
        Route::get('campaigns/{campaign}/npcs/{npc}/states', [ControlNpcController::class, 'states']);
        Route::post('campaigns/{campaign}/npcs/{npc}/states', [ControlNpcController::class, 'storeState']);
        Route::post('campaigns/{campaign}/assets/uploads', [ControlAssetController::class, 'initiate']);
        Route::post('campaigns/{campaign}/assets/{asset}/complete', [ControlAssetController::class, 'complete']);
        Route::get('campaigns/{campaign}/assets/{asset}/read', [ControlAssetController::class, 'read']);
        Route::patch('campaigns/{campaign}', [ControlCampaignController::class, 'update']);
        Route::post('campaigns/{campaign}/publish', [ControlCampaignController::class, 'publish']);
        Route::delete('campaigns/{campaign}', [ControlCampaignController::class, 'destroy']);
    });
});
