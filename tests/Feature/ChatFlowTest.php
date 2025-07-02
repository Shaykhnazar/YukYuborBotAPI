<?php
// tests/Feature/ChatFlowTest.php

use App\Models\User;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\SendRequest;
use App\Models\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates chat and sends messages between users', function () {
    $sender = User::factory()->create(['links_balance' => 5]);
    $deliverer = User::factory()->create();

    $sendRequest = SendRequest::factory()->create(['user_id' => $sender->id]);

    // Create chat
    $chatResponse = $this->postJson('/api/chat/create', [
        'request_type' => 'send',
        'request_id' => $sendRequest->id,
        'other_user_id' => $deliverer->id
    ], [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $sender->telegramUser->telegram])
    ]);

    $chatResponse->assertStatus(200);
    $chatId = $chatResponse->json('chat_id');

    // Send message
    $messageResponse = $this->postJson('/api/chat/message', [
        'chat_id' => $chatId,
        'message' => 'Hello, can you deliver my package?'
    ], [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $sender->telegramUser->telegram])
    ]);

    $messageResponse->assertStatus(200);

    // Verify message was created
    expect(ChatMessage::count())->toBe(1);

    $message = ChatMessage::first();
    expect($message->message)->toBe('Hello, can you deliver my package?');
    expect($message->sender_id)->toBe($sender->id);
});
