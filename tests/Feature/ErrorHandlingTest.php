<?php
// tests/Feature/ErrorHandlingTest.php

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('handles insufficient balance for chat creation', function () {
    $user = User::factory()->create(['links_balance' => 0]);
    $otherUser = User::factory()->create();

    $response = $this->postJson('/api/chat/create', [
        'request_type' => 'send',
        'request_id' => 1,
        'other_user_id' => $otherUser->id
    ], [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $user->telegramUser->telegram])
    ]);

    $response->assertStatus(403);
    $response->assertJson([
        'error' => 'Insufficient links balance'
    ]);
});

it('prevents duplicate reviews', function () {
    $reviewer = User::factory()->create();
    $reviewee = User::factory()->create();

    // Create first review
    Review::factory()->create([
        'user_id' => $reviewee->id,
        'owner_id' => $reviewer->id
    ]);

    // Try to create duplicate review
    $response = $this->postJson('/api/review-request', [
        'user_id' => $reviewee->id,
        'text' => 'Another review',
        'rating' => 4
    ], [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $reviewer->telegramUser->telegram])
    ]);

    $response->assertStatus(409);
    $response->assertJson([
        'error' => 'You have already reviewed this user'
    ]);
});
