<?php
// tests/Feature/BroadcastingTest.php

use App\Models\User;
use App\Models\Chat;
use App\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('broadcasts message sent event', function () {
    Event::fake();

    $sender = User::factory()->create();
    $receiver = User::factory()->create();

    $chat = Chat::factory()->create([
        'sender_id' => $sender->id,
        'receiver_id' => $receiver->id
    ]);

    $this->postJson('/api/chat/message', [
        'chat_id' => $chat->id,
        'message' => 'Test message'
    ], [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $sender->telegramUser->telegram])
    ]);

    Event::assertDispatched(MessageSent::class);
});
