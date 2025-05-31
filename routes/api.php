<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\ResponseController;
use App\Http\Controllers\SendRequestController;
use App\Http\Controllers\User\Request\UserRequestController;
use App\Http\Controllers\User\Review\UserReviewController;
use App\Http\Controllers\User\UserController;
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

// Determine middleware based on environment
$middleware = ['tg.init'];
if (!env('TELEGRAM_DEV_MODE', false) || app()->environment() === 'production') {
    $middleware[] = 'auth:tgwebapp';
}

Route::middleware($middleware)->group(function () {
    Route::get('/user', [UserController::class, 'index']);
    Route::get('/user/requests', [UserRequestController::class, 'index']);
    Route::get('/user/{user}', [UserController::class, 'show']);
    Route::get('/requests', [RequestController::class, 'index']);
    Route::post('/send-request', [SendRequestController::class, 'create']);
    Route::post('/delivery-request', [DeliveryController::class, 'create']);
    Route::post('/review-request', [UserReviewController::class, 'create']);
    Route::get('/reviews/{id}', [UserReviewController::class, 'show']);
    Route::get('/user/reviews/{userId}', [UserReviewController::class, 'userReviews']);
    Route::get('/requests/{id}', [UserRequestController::class, 'show']);
    Route::get('user/{user}/requests', [UserRequestController::class, 'userRequests']);

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

    // Responses routes
    Route::prefix('responses')->controller(ResponseController::class)->group(function () {
        // Get all responses for current user
        Route::get('/', 'index');
        // Accept a response
        Route::post('/{responseId}/accept', 'accept');
        // Reject a response
        Route::post('/{responseId}/reject', 'reject');
        // Cancel a response
        Route::post('/{responseId}/cancel', 'cancel');
    });
});

Route::get('/place', [PlaceController::class, 'index']);

