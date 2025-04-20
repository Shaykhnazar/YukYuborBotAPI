<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware( ['auth:tgwebapp', 'tg.init'] )->group(function () {
    Route::get('/user', [\App\Http\Controllers\User\Controller::class, 'index']);
    Route::get('/user/requests', [\App\Http\Controllers\User\Requests\Controller::class, 'index']);
    Route::get('/user/{user}', [\App\Http\Controllers\User\Controller::class, 'show']);
    Route::get('/requests', [\App\Http\Controllers\RequestsController::class, 'index']);
    Route::post('/send-request', [\App\Http\Controllers\SendRequest\Controller::class, 'create']);
});

