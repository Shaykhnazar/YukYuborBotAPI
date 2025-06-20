<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\ResponseController;
use App\Http\Controllers\SendRequestController;
use App\Http\Controllers\TestUsersController;
use App\Http\Controllers\User\Request\UserRequestController;
use App\Http\Controllers\User\Review\UserReviewController;
use App\Http\Controllers\User\UserController;
use App\Service\TelegramUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
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
$middleware = [];

if (app()->environment(['local', 'development'])) {
    // Development/Local environment - use development middleware
    $middleware[] = 'tg.init.dev';
    // Skip the auth:tgwebapp middleware in development
} else {
    // Production environment - use production middleware + auth
    $middleware[] = 'tg.init';
    $middleware[] = 'auth:tgwebapp';
}

Route::middleware($middleware)->group(function () {
    Route::get('/user', [UserController::class, 'index']);
    Route::get('/user/requests', [UserRequestController::class, 'index']);
    Route::get('/user/{user}', [UserController::class, 'show']);
    Route::get('/requests', [RequestController::class, 'index']);
    Route::post('/send-request', [SendRequestController::class, 'create']);
    Route::post('/delivery-request', [DeliveryController::class, 'create']);
    Route::get('/requests/{id}', [UserRequestController::class, 'show']);
    Route::get('user/{user}/requests', [UserRequestController::class, 'userRequests']);
    // Review routes
    Route::post('/review-request', [UserReviewController::class, 'create']);
    Route::get('/reviews/{id}', [UserReviewController::class, 'show']);
    Route::get('/user/reviews/{userId}', [UserReviewController::class, 'userReviews']);
    // Delete request routes
    Route::delete('/send-request/{id}', [SendRequestController::class, 'delete']);
    Route::delete('/delivery-request/{id}', [DeliveryController::class, 'delete']);
    // Close request routes
    Route::patch('/send-request/{id}/close', [SendRequestController::class, 'close']);
    Route::patch('/delivery-request/{id}/close', [DeliveryController::class, 'close']);

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
        // Typing and read receipt routes
        Route::post('/typing', [ChatController::class, 'setTyping']);
        Route::post('/{chatId}/mark-read', [ChatController::class, 'markAsRead']);
        Route::get('/{chatId}/online-users', [ChatController::class, 'getOnlineUsers']);
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

Route::get('/place', [PlaceController::class, 'index'])->middleware(['throttle:60,1']);

// Development-only routes (secured)
Route::middleware(['throttle:60,1'])->group(function () {
    // Test users endpoint - only accessible in development with proper headers
    Route::get('/dev/test-users', [TestUsersController::class, 'index']);
});

// Health check route (useful for deployment)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'environment' => app()->environment(),
        'timestamp' => now()->toISOString(),
        'version' => '1.0.0'
    ]);
});

Route::middleware($middleware)->post('/broadcasting/auth', function (Request $request) {
    try {
        Log::info('Broadcasting auth request received', [
            'url' => $request->url(),
            'method' => $request->method(),
            'has_telegram_data' => $request->hasHeader('X-TELEGRAM-USER-DATA'),
            'socket_id' => $request->input('socket_id'),
            'channel_name' => $request->input('channel_name')
        ]);

        // Get user through your existing middleware/service
        $telegramUserService = app(TelegramUserService::class);
        $user = $telegramUserService->getUserByTelegramId($request);

        if (!$user) {
            Log::warning('Broadcasting auth failed - no user found');
            return response()->json(['error' => 'User not found'], 401);
        }

        Log::info('Broadcasting auth user found', [
            'user_id' => $user->id,
            'user_name' => $user->name
        ]);

        $socketId = $request->input('socket_id');
        $channelName = $request->input('channel_name');

        if (!$socketId || !$channelName) {
            Log::warning('Broadcasting auth failed - missing socket_id or channel_name', [
                'socket_id' => $socketId,
                'channel_name' => $channelName
            ]);
            return response()->json(['error' => 'Missing required parameters'], 400);
        }

        // Manual channel authorization based on your channel definitions
        if (preg_match('/^private-chat\.(\d+)$/', $channelName, $matches)) {
            $chatId = (int) $matches[1];

            Log::info('Authorizing private chat channel', [
                'chat_id' => $chatId,
                'user_id' => $user->id
            ]);

            // Check if user can access this chat
            $chat = \App\Models\Chat::find($chatId);
            if (!$chat) {
                Log::warning('Chat not found for authorization', ['chat_id' => $chatId]);
                return response()->json(['error' => 'Chat not found'], 404);
            }

            $canAccess = in_array($user->id, [$chat->sender_id, $chat->receiver_id]);

            if (!$canAccess) {
                Log::warning('User cannot access chat', [
                    'user_id' => $user->id,
                    'chat_id' => $chatId,
                    'sender_id' => $chat->sender_id,
                    'receiver_id' => $chat->receiver_id
                ]);
                return response()->json(['error' => 'Access denied'], 403);
            }

            // Generate auth signature manually
            $stringToSign = $socketId . ':' . $channelName;
            $authSignature = hash_hmac('sha256', $stringToSign, config('reverb.apps.apps.0.secret'));

            Log::info('Chat channel authorization successful', [
                'user_id' => $user->id,
                'chat_id' => $chatId
            ]);

            return response()->json([
                'auth' => config('reverb.apps.apps.0.key') . ':' . $authSignature
            ]);
        }

        // Handle presence channels
        if (preg_match('/^presence-chat\.(\d+)\.presence$/', $channelName, $matches)) {
            $chatId = (int) $matches[1];

            Log::info('Authorizing presence channel', [
                'chat_id' => $chatId,
                'user_id' => $user->id
            ]);

            // Check if user can access this chat
            $chat = \App\Models\Chat::find($chatId);
            if (!$chat) {
                Log::warning('Chat not found for presence authorization', ['chat_id' => $chatId]);
                return response()->json(['error' => 'Chat not found'], 404);
            }

            $canAccess = in_array($user->id, [$chat->sender_id, $chat->receiver_id]);

            if (!$canAccess) {
                Log::warning('User cannot access presence channel', [
                    'user_id' => $user->id,
                    'chat_id' => $chatId
                ]);
                return response()->json(['error' => 'Access denied'], 403);
            }

            // For presence channels, include user data
            $userData = [
                'id' => $user->id,
                'name' => $user->name,
            ];

            $stringToSign = $socketId . ':' . $channelName . ':' . json_encode($userData);
            $authSignature = hash_hmac('sha256', $stringToSign, config('reverb.apps.apps.0.secret'));

            Log::info('Presence channel authorization successful', [
                'user_id' => $user->id,
                'chat_id' => $chatId
            ]);

            return response()->json([
                'auth' => config('reverb.apps.apps.0.key') . ':' . $authSignature,
                'channel_data' => json_encode($userData)
            ]);
        }

        Log::warning('Unknown channel type for authorization', ['channel_name' => $channelName]);
        return response()->json(['error' => 'Unknown channel type'], 400);

    } catch (\Exception $e) {
        Log::error('Broadcasting auth exception', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json(['error' => 'Authentication failed'], 500);
    }
});
