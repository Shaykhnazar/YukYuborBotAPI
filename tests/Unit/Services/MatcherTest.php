<?php
// tests/Unit/Services/MatcherTest.php

use App\Models\User;
use App\Service\Matcher;
use App\Service\TelegramNotificationService;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
use Mockery;

beforeEach(function () {
    $this->telegramService = Mockery::mock(TelegramNotificationService::class);
    $this->matcher = new Matcher($this->telegramService);
});

it('matches send request with delivery requests', function () {
    $sender = User::factory()->create();
    $deliverer = User::factory()->create();

    $sendRequest = SendRequest::factory()->create([
        'user_id' => $sender->id,
        'from_location' => 'Tashkent',
        'to_location' => 'Samarkand',
        'from_date' => '2024-01-01',
        'to_date' => '2024-01-05',
        'status' => 'open'
    ]);

    $deliveryRequest = DeliveryRequest::factory()->create([
        'user_id' => $deliverer->id,
        'from_location' => 'Tashkent',
        'to_location' => 'Samarkand',
        'from_date' => '2024-01-01',
        'to_date' => '2024-01-05',
        'status' => 'open'
    ]);

    $this->telegramService->shouldReceive('buildDeliveryResponseKeyboard')
        ->once()
        ->andReturn(['keyboard' => 'data']);

    $this->telegramService->shouldReceive('sendMessageWithKeyboard')
        ->once();

    $this->matcher->matchSendRequest($sendRequest);

    expect(Response::count())->toBe(1);

    $response = Response::first();
    expect($response->user_id)->toBe($deliverer->id);
    expect($response->responder_id)->toBe($sender->id);
    expect($response->status)->toBe('pending');
});
