<?php
// tests/Unit/Controllers/ChatControllerTest.php

use App\Http\Controllers\ChatController;
use App\Service\TelegramUserService;
use App\Models\User;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;

beforeEach(function () {
    $this->telegramService = Mockery::mock(TelegramUserService::class);
    $this->controller = new ChatController($this->telegramService);
});

it('returns user chats with unread count', function () {
    Event::fake();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $chat = Chat::factory()->create([
        'sender_id' => $user->id,
        'receiver_id' => $otherUser->id
    ]);

    $unreadMessage = ChatMessage::factory()->create([
        'chat_id' => $chat->id,
        'sender_id' => $otherUser->id,
        'is_read' => false
    ]);

    $request = Mockery::mock(Request::class);

    $this->telegramService->shouldReceive('getUserByTelegramId')
        ->with($request)
        ->andReturn($user);

    $response = $this->controller->index($request);

    $data = $response->getData(true);
    expect($data[0]['unread_count'])->toBe(1);
    expect($data[0]['other_user']['id'])->toBe($otherUser->id);
});
