<?php

use App\Http\Controllers\LivenessController;
use App\Http\Controllers\ReadinessController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return redirect('/control');
});

Route::view('/control', 'control');
Route::view('/player', 'participant');
Route::view('/presentation', 'presentation');
Route::get('/live', LivenessController::class);
Route::get('/ready', ReadinessController::class)->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
]);
