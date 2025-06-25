<?php

use App\Models\User;
use App\Models\TelegramUser;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Review;
use App\Models\Chat;

it('has telegram user relationship', function () {
    $user = User::factory()->create();
    $telegramUser = TelegramUser::factory()->create(['user_id' => $user->id]);

    expect($user->telegramUser)->toBeInstanceOf(TelegramUser::class);
    expect($user->telegramUser->id)->toBe($telegramUser->id);
});

it('has send requests relationship', function () {
    $user = User::factory()->create();
    $sendRequest = SendRequest::factory()->create(['user_id' => $user->id]);

    expect($user->sendRequests)->toHaveCount(1);
    expect($user->sendRequests->first()->id)->toBe($sendRequest->id);
});

it('gets all chats for user', function () {
    $user = User::factory()->create();
    $chat1 = Chat::factory()->create(['sender_id' => $user->id]);
    $chat2 = Chat::factory()->create(['receiver_id' => $user->id]);

    $chats = $user->getAllChats();

    expect($chats)->toHaveCount(2);
});
