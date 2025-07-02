<?php
// tests/Unit/Services/TelegramUserServiceTest.php

use App\Service\TelegramUserService;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Http\Request;
use Mockery;

beforeEach(function () {
    $this->service = new TelegramUserService();
});

it('gets user by telegram id', function () {
    $user = User::factory()->create();
    $telegramUser = TelegramUser::factory()->create([
        'telegram' => 123456789,
        'user_id' => $user->id
    ]);

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('get')
        ->with('telegram_id')
        ->andReturn(123456789);

    $result = $this->service->getUserByTelegramId($request);

    expect($result->id)->toBe($user->id);
});

it('returns null when telegram user not found', function () {
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('get')
        ->with('telegram_id')
        ->andReturn(999999999);

    $result = $this->service->getUserByTelegramId($request);

    expect($result)->toBeNull();
});
