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
        'user_name' => $user->name ?? 'null'
    ]);

    if (!$user || !$user->id) {
        Log::warning('❌ No authenticated user for presence channel');
        return false;
    }

    $chat = Chat::find($chatId);
    if (!$chat) {
        Log::warning('❌ Chat not found for presence', ['chat_id' => $chatId]);
        return false;
    }

    $authorized = in_array($user->id, [$chat->sender_id, $chat->receiver_id]);

    if ($authorized) {
        Log::info('✅ Presence channel authorized', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'chat_id' => $chatId
        ]);

        // Return user data for presence channel
        return [
            'id' => $user->id,
            'name' => $user->name,
            'image' => $user->telegramUser->image ?? null,
        ];
    }

    Log::warning('❌ Presence channel not authorized', [
        'user_id' => $user->id,
        'sender_id' => $chat->sender_id,
        'receiver_id' => $chat->receiver_id
    ]);

    return false;
});
