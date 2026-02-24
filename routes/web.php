<?php

use App\Http\Controllers\C1Controller;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/get-token', [C1Controller::class, 'getToken']);
Route::get('/subscriber', [C1Controller::class, 'subscriberRetrieve']);
