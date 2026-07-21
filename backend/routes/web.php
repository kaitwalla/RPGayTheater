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
Route::view('/control/{path}', 'control')->where('path', '.*');
Route::view('/player', 'participant');
Route::view('/presentation', 'presentation');

$healthMiddlewareExclusions = [
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
];

Route::get('/live', LivenessController::class)->withoutMiddleware($healthMiddlewareExclusions);
Route::get('/ready', ReadinessController::class)->withoutMiddleware($healthMiddlewareExclusions);
