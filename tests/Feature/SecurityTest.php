<?php
// tests/Feature/SecurityTest.php

use App\Models\User;
use App\Models\Chat;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prevents unauthorized chat access', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $user3 = User::factory()->create(); // Unauthorized user

    $chat = Chat::factory()->create([
        'sender_id' => $user1->id,
        'receiver_id' => $user2->id
    ]);

    // Try to access chat as unauthorized user
    $response = $this->getJson("/api/chat/{$chat->id}", [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $user3->telegramUser->telegram])
    ]);

    $response->assertStatus(404); // Should not find the chat
});

it('prevents sending messages to unauthorized chat', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $user3 = User::factory()->create();

    $chat = Chat::factory()->create([
        'sender_id' => $user1->id,
        'receiver_id' => $user2->id
    ]);

    $response = $this->postJson('/api/chat/message', [
        'chat_id' => $chat->id,
        'message' => 'Unauthorized message'
    ], [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $user3->telegramUser->telegram])
    ]);

    $response->assertStatus(404);
});
