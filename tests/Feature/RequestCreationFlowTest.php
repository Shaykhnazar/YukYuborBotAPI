<?php
// tests/Feature/RequestCreationFlowTest.php

use App\Models\User;
use App\Models\TelegramUser;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates send request and matches with delivery request', function () {
    // Create users
    $sender = User::factory()->create();
    $deliverer = User::factory()->create();

    TelegramUser::factory()->create(['user_id' => $sender->id, 'telegram' => 123]);
    TelegramUser::factory()->create(['user_id' => $deliverer->id, 'telegram' => 456]);

    // Create delivery request first
    $deliveryRequest = DeliveryRequest::factory()->create([
        'user_id' => $deliverer->id,
        'from_location' => 'Tashkent',
        'to_location' => 'Samarkand',
        'status' => 'open'
    ]);

    // Create send request (should trigger matching)
    $response = $this->postJson('/api/send-request', [
        'from_location' => 'Tashkent',
        'to_location' => 'Samarkand',
        'from_date' => '2024-01-01',
        'to_date' => '2024-01-05',
        'description' => 'Test package'
    ], [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => 123])
    ]);

    $response->assertStatus(200);

    // Verify matching occurred
    expect(Response::count())->toBe(1);

    $matchResponse = Response::first();
    expect($matchResponse->user_id)->toBe($deliverer->id);
    expect($matchResponse->status)->toBe('pending');
});
