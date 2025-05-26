<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Chat\Controller as ChatController;

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

// Determine middleware based on environment
$middleware = ['tg.init'];
if (!env('TELEGRAM_DEV_MODE', false)) {
    $middleware[] = 'auth:tgwebapp';
}

Route::middleware($middleware)->group(function () {
    Route::get('/user', [\App\Http\Controllers\User\Controller::class, 'index']);
    Route::get('/user/requests', [\App\Http\Controllers\User\Requests\Controller::class, 'index']);
    Route::get('/user/{user}', [\App\Http\Controllers\User\Controller::class, 'show']);
    Route::get('/requests', [\App\Http\Controllers\RequestsController::class, 'index']);
    Route::post('/send-request', [\App\Http\Controllers\SendRequest\Controller::class, 'create']);
    Route::post('/delivery-request', [\App\Http\Controllers\DeliveryRequest\Controller::class, 'create']);
    Route::post('/review-request', [\App\Http\Controllers\User\Review\Controller::class, 'create']);
    Route::get('/reviews/{id}', [\App\Http\Controllers\User\Review\Controller::class, 'show']);
    Route::get('/user/reviews/{userId}', [\App\Http\Controllers\User\Review\Controller::class, 'userReviews']);
    Route::get('/requests/{id}', [\App\Http\Controllers\User\Requests\Controller::class, 'show']);
    Route::get('user/{user}/requests', [\App\Http\Controllers\User\Requests\Controller::class, 'userRequests']);

    // Chat routes
    Route::prefix('chat')->group(function () {
        // Get all chats for current user
        Route::get('/', [ChatController::class, 'index']);
        // Get specific chat with messages
        Route::get('/{chatId}', [ChatController::class, 'show']);
        // Send message in chat
        Route::post('/message', [ChatController::class, 'sendMessage']);
        // Create new chat (start dialog)
        Route::post('/create', [ChatController::class, 'createChat']);
    });
});
Route::get('/place', [\App\Http\Controllers\Place\Controller::class, 'index']);

