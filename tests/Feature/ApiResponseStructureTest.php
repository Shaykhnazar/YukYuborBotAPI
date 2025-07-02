<?php
// tests/Feature/ApiResponseStructureTest.php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns correct user profile structure', function () {
    $user = User::factory()->create();

    $response = $this->getJson('/api/user', [
        'X-TELEGRAM-USER-DATA' => json_encode(['id' => $user->telegramUser->telegram])
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'telegram' => [
            'id',
            'telegram',
            'username',
            'image'
        ],
        'user' => [
            'id',
            'name',
            'city',
            'links_balance',
            'created_at',
            'updated_at',
            'with_us'
        ],
        'reviews',
        'average_rating'
    ]);
});
