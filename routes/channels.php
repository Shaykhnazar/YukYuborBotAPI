<?php

use App\Models\Chat;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private channel for specific chat rooms
Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    Log::info('🔐 Authorizing private chat channel', [
        'user_id' => $user->id ?? 'null',
        'chat_id' => $chatId,
        'user_object' => $user ? get_class($user) : 'null'
    ]);

    if (!$user || !$user->id) {
        Log::warning('❌ No authenticated user for chat channel');
        return false;
    }

    $chat = Chat::find($chatId);
    if (!$chat) {
        Log::warning('❌ Chat not found', ['chat_id' => $chatId]);
        return false;
    }

    $authorized = in_array($user->id, [$chat->sender_id, $chat->receiver_id]);

    Log::info('🔐 Chat channel authorization result', [
        'authorized' => $authorized,
        'user_id' => $user->id,
        'sender_id' => $chat->sender_id,
        'receiver_id' => $chat->receiver_id
    ]);

    return $authorized;
});

// Presence channel for online users in chat
Broadcast::channel('chat.{chatId}.presence', function ($user, $chatId) {
    Log::info('🔐 Authorizing presence channel', [
        'user_id' => $user->id ?? 'null',
        'chat_id' => $chatId,
        'user_name' => $user->name ?? 'null',
        'user_class' => get_class($user)
    ]);

    if (!$user || !$user->id) {
        Log::warning('❌ No authenticated user for presence channel');
        return false;
    }

    // Ensure telegram user relationship is loaded
    if (!$user->relationLoaded('telegramUser')) {
        $user->load('telegramUser');
    }

    $chat = Chat::find($chatId);
    if (!$chat) {
        Log::warning('❌ Chat not found for presence', ['chat_id' => $chatId]);
        return false;
    }

    $authorized = in_array($user->id, [$chat->sender_id, $chat->receiver_id]);

    if ($authorized) {
        // 🔧 CRITICAL: Validate user data before returning
        $userData = [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'image' => $user->telegramUser && $user->telegramUser->image
                ? (string) $user->telegramUser->image
                : null,
        ];

        // Additional validation
        if (!$userData['id'] || !$userData['name']) {
            Log::error('❌ Invalid user data in presence channel', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_data' => $userData,
                'user_attributes' => $user->getAttributes()
            ]);
            return false;
        }

        Log::info('✅ Presence channel authorized', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'chat_id' => $chatId,
            'returned_data' => $userData
        ]);

        // Return user data for presence channel
        return $userData;
    }

    Log::warning('❌ Presence channel not authorized', [
        'user_id' => $user->id,
        'sender_id' => $chat->sender_id,
        'receiver_id' => $chat->receiver_id
    ]);

    return false;
});
