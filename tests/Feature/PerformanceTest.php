<?php
// tests/Feature/PerformanceTest.php

use App\Models\User;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('efficiently loads user requests with relationships', function () {
    $user = User::factory()->create();

    // Create multiple requests
    SendRequest::factory()->count(10)->create(['user_id' => $user->id]);
    DeliveryRequest::factory()->count(10)->create(['user_id' => $user->id]);

    DB::enableQueryLog();

    $response = $this->getJson('/api/user/requests', [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $user->telegramUser->telegram])
    ]);

    $queries = DB::getQueryLog();

    $response->assertStatus(200);
    // Should not have N+1 query problems
    expect(count($queries))->toBeLessThan(10);
});
