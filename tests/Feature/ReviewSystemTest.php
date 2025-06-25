<?php
// tests/Feature/ReviewSystemTest.php

use App\Models\User;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates review and calculates average rating', function () {
    $reviewer = User::factory()->create();
    $reviewee = User::factory()->create();

    // Create review
    $response = $this->postJson('/api/review-request', [
        'user_id' => $reviewee->id,
        'text' => 'Great service!',
        'rating' => 5
    ], [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $reviewer->telegramUser->telegram])
    ]);

    $response->assertStatus(200);

    // Verify review was created
    expect(Review::count())->toBe(1);

    $review = Review::first();
    expect($review->rating)->toBe(5);
    expect($review->user_id)->toBe($reviewee->id);
    expect($review->owner_id)->toBe($reviewer->id);

    // Test user profile shows correct average rating
    $profileResponse = $this->getJson("/api/user/{$reviewee->id}", [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $reviewer->telegramUser->telegram])
    ]);

    $profileResponse->assertStatus(200);
    $profileResponse->assertJson([
        'average_rating' => 5.0
    ]);
});
