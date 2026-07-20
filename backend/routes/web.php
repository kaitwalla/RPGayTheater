<?php

use App\Http\Controllers\LivenessController;
use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/control');
});

Route::view('/control', 'control');
Route::view('/player', 'participant');
Route::view('/presentation', 'presentation');
Route::get('/live', LivenessController::class);
Route::get('/ready', ReadinessController::class);
