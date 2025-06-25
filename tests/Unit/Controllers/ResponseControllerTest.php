<?php
// tests/Unit/Controllers/ResponseControllerTest.php

use App\Http\Controllers\ResponseController;
use App\Models\Chat;
use App\Service\TelegramUserService;
use App\Service\Matcher;
use App\Models\User;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use Illuminate\Http\Request;
use Mockery;

beforeEach(function () {
    $this->telegramService = Mockery::mock(TelegramUserService::class);
    $this->matcher = Mockery::mock(Matcher::class);
    $this->controller = new ResponseController($this->telegramService, $this->matcher);
});

it('accepts deliverer response and creates chat', function () {
    $sender = User::factory()->create(['links_balance' => 5]);
    $deliverer = User::factory()->create();

    $sendRequest = SendRequest::factory()->create(['user_id' => $sender->id]);
    $deliveryRequest = DeliveryRequest::factory()->create(['user_id' => $deliverer->id]);

    $response = Response::factory()->create([
        'user_id' => $sender->id,
        'responder_id' => $deliverer->id,
        'request_type' => 'delivery',
        'request_id' => $sendRequest->id,
        'offer_id' => $deliveryRequest->id,
        'status' => 'waiting'
    ]);

    $request = Mockery::mock(Request::class);

    $this->telegramService->shouldReceive('getUserByTelegramId')
        ->with($request)
        ->andReturn($sender);

    $responseId = "delivery_{$deliveryRequest->id}_send_{$sendRequest->id}";
    $result = $this->controller->accept($request, $responseId);

    expect($result->getStatusCode())->toBe(200);
    expect(Chat::count())->toBe(1);

    $chat = Chat::first();
    expect($chat->sender_id)->toBe($sender->id);
    expect($chat->receiver_id)->toBe($deliverer->id);
});
