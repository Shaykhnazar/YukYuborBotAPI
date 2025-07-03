<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/
// Broadcasting authentication route - CRITICAL for presence channels
//Broadcast::routes(['middleware' => ['tg.init', 'auth:tgwebapp']]);
//
//// For development, also add without auth middleware
//if (app()->environment(['local', 'development'])) {
//    Broadcast::routes(['middleware' => ['tg.init.dev']]);
//}
