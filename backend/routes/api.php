<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ControlAssetController;
use App\Http\Controllers\Api\ControlAudioCueController;
use App\Http\Controllers\Api\ControlAuthenticationController;
use App\Http\Controllers\Api\ControlCampaignController;
use App\Http\Controllers\Api\ControlCampaignMapController;
use App\Http\Controllers\Api\ControlDicePresetController;
use App\Http\Controllers\Api\ControlLiveSessionController;
use App\Http\Controllers\Api\ControlNpcController;
use App\Http\Controllers\Api\ControlPlayerCharacterController;
use App\Http\Controllers\Api\ControlSceneController;
use App\Http\Controllers\Api\ControlSessionParticipantController;
use App\Http\Controllers\Api\ControlStagePresetController;
use App\Http\Controllers\Api\ControlVideoCueController;
use App\Http\Controllers\Api\ParticipantClaimController;
use App\Http\Controllers\Api\ParticipantSessionController;
use App\Http\Controllers\Api\PresentationPairingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web'])->prefix('control/v1')->group(function (): void {
    Route::get('auth', [ControlAuthenticationController::class, 'show']);
    Route::post('auth/login', [ControlAuthenticationController::class, 'login'])->middleware('throttle:control-login');
    Route::post('auth/logout', [ControlAuthenticationController::class, 'logout'])->middleware('control');

    Route::middleware('control')->group(function (): void {
        Route::get('campaigns', [ControlCampaignController::class, 'index']);
        Route::post('campaigns', [ControlCampaignController::class, 'store']);
        Route::post('campaigns/import', [ControlCampaignController::class, 'import']);
        Route::get('campaigns/{campaign}/revisions', [ControlCampaignController::class, 'revisions']);
        Route::get('campaigns/{campaign}/revisions/{revision}', [ControlCampaignController::class, 'revision']);
        Route::get('campaigns/{campaign}/revisions/{revision}/package', [ControlCampaignController::class, 'exportRevision']);
        Route::get('campaigns/{campaign}/sessions', [ControlLiveSessionController::class, 'index']);
        Route::post('campaigns/{campaign}/sessions', [ControlLiveSessionController::class, 'store']);
        Route::get('campaigns/{campaign}/sessions/{session}/participants', [ControlSessionParticipantController::class, 'index']);
        Route::delete('campaigns/{campaign}/sessions/{session}/participants/{participant}/claim', [ControlSessionParticipantController::class, 'release']);
        Route::delete('campaigns/{campaign}/sessions/{session}/participants/{participant}', [ControlSessionParticipantController::class, 'revoke']);
        Route::get('campaigns/{campaign}/assets', [ControlAssetController::class, 'index']);
        Route::get('campaigns/{campaign}/audio-cues', [ControlAudioCueController::class, 'index']);
        Route::post('campaigns/{campaign}/audio-cues', [ControlAudioCueController::class, 'store']);
        Route::get('campaigns/{campaign}/video-cues', [ControlVideoCueController::class, 'index']);
        Route::post('campaigns/{campaign}/video-cues', [ControlVideoCueController::class, 'store']);
        Route::get('campaigns/{campaign}/dice-presets', [ControlDicePresetController::class, 'index']);
        Route::post('campaigns/{campaign}/dice-presets', [ControlDicePresetController::class, 'store']);
        Route::get('campaigns/{campaign}/scenes', [ControlSceneController::class, 'index']);
        Route::post('campaigns/{campaign}/scenes', [ControlSceneController::class, 'store']);
        Route::get('campaigns/{campaign}/scenes/{scene}/backdrops', [ControlSceneController::class, 'backdrops']);
        Route::post('campaigns/{campaign}/scenes/{scene}/backdrops', [ControlSceneController::class, 'storeBackdrop']);
        Route::get('campaigns/{campaign}/stage-presets', [ControlStagePresetController::class, 'index']);
        Route::post('campaigns/{campaign}/stage-presets', [ControlStagePresetController::class, 'store']);
        Route::get('campaigns/{campaign}/stage-presets/{stagePreset}/entries', [ControlStagePresetController::class, 'entries']);
        Route::post('campaigns/{campaign}/stage-presets/{stagePreset}/entries', [ControlStagePresetController::class, 'storeEntry']);
        Route::get('campaigns/{campaign}/maps', [ControlCampaignMapController::class, 'index']);
        Route::post('campaigns/{campaign}/maps', [ControlCampaignMapController::class, 'store']);
        Route::get('campaigns/{campaign}/maps/{map}/fog-mask', [ControlCampaignMapController::class, 'fogMask']);
        Route::put('campaigns/{campaign}/maps/{map}/fog-mask', [ControlCampaignMapController::class, 'setFogMask']);
        Route::get('campaigns/{campaign}/maps/{map}/tokens', [ControlCampaignMapController::class, 'tokens']);
        Route::post('campaigns/{campaign}/maps/{map}/tokens', [ControlCampaignMapController::class, 'storeToken']);
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

Route::middleware(['web'])->prefix('presentation/v1')->group(function (): void {
    Route::post('pair', [PresentationPairingController::class, 'pair']);
});

Route::middleware(['web'])->prefix('participant/v1')->group(function (): void {
    Route::post('join', [ParticipantSessionController::class, 'join']);
    Route::post('resume', [ParticipantSessionController::class, 'resume']);
    Route::post('claim', [ParticipantClaimController::class, 'claim']);
});
