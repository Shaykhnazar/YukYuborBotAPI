<?php
// tests/Unit/Controllers/RequestControllerTest.php

use App\Http\Controllers\RequestController;
use App\Http\Requests\Parcel\ParcelRequest;
use App\Service\TelegramUserService;
use App\Models\User;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use Illuminate\Http\Request;
use Mockery;

beforeEach(function () {
    $this->telegramService = Mockery::mock(TelegramUserService::class);
    $this->controller = new RequestController($this->telegramService);
});

it('returns all requests excluding current user requests', function () {
    $currentUser = User::factory()->create();
    $otherUser = User::factory()->create();

    $currentUserRequest = SendRequest::factory()->create(['user_id' => $currentUser->id]);
    $otherUserRequest = SendRequest::factory()->create(['user_id' => $otherUser->id]);

    $request = Mockery::mock(ParcelRequest::class);
    $request->shouldReceive('getFilter')->andReturn(null);

    $this->telegramService->shouldReceive('getUserByTelegramId')
        ->with($request)
        ->andReturn($currentUser);

    $response = $this->controller->index($request);

    expect($response->count())->toBe(1);
    expect($response->first()->id)->toBe($otherUserRequest->id);
});
