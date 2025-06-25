<?php
// tests/Unit/Controllers/UserControllerTest.php

use App\Http\Controllers\User\UserController;
use App\Service\TelegramUserService;
use App\Models\User;
use App\Models\Review;
use Illuminate\Http\Request;
use Mockery;

beforeEach(function () {
    $this->telegramService = Mockery::mock(TelegramUserService::class);
    $this->controller = new UserController($this->telegramService);
});

it('returns user profile with reviews and rating', function () {
    $user = User::factory()->create();
    $review1 = Review::factory()->create(['user_id' => $user->id, 'rating' => 4]);
    $review2 = Review::factory()->create(['user_id' => $user->id, 'rating' => 5]);

    $request = Mockery::mock(Request::class);

    $this->telegramService->shouldReceive('getUserByTelegramId')
        ->with($request)
        ->andReturn($user);

    $response = $this->controller->index($request);

    expect($response->getStatusCode())->toBe(200);

    $data = $response->getData(true);
    expect($data['average_rating'])->toBe(4.5);
    expect($data['user']['id'])->toBe($user->id);
});
