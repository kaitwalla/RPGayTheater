<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/control');
});

Route::view('/control', 'control');
