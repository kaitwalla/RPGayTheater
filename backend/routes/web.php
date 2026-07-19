<?php

use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/control');
});

Route::view('/control', 'control');
Route::get('/ready', ReadinessController::class);
