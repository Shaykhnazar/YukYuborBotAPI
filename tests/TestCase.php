<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use App\Models\User;
use App\Models\TelegramUser;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable actual HTTP requests during testing
        Http::preventStrayRequests();

        // Fake external services by default
        Event::fake();
        Notification::fake();
        Queue::fake();

        // Set up common test configurations
        config([
            'auth.guards.tgwebapp.token' => 'test_telegram_bot_token',
            'reverb.apps.apps.0.key' => 'test_reverb_key',
            'reverb.apps.apps.0.secret' => 'test_reverb_secret',
        ]);

        // Create database trigger function if not exists
        $this->createTestDatabaseTriggers();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create authenticated user for testing
     */
    protected function createAuthenticatedUser(array $userAttributes = [], array $telegramAttributes = []): User
    {
        $user = User::factory()->create($userAttributes);

        $telegramUser = TelegramUser::factory()
            ->forUser($user)
            ->create($telegramAttributes);

        return $user;
    }

    /**
     * Create two users for interaction testing
     */
    protected function createTwoUsers(): array
    {
        $user1 = $this->createAuthenticatedUser(['name' => 'Test User 1']);
        $user2 = $this->createAuthenticatedUser(['name' => 'Test User 2']);

        return [$user1, $user2];
    }

    /**
     * Get Telegram auth headers for API requests
     */
    protected function getTelegramHeaders(User $user): array
    {
        $telegramData = [
            'id' => $user->telegramUser->telegram,
            'first_name' => explode(' ', $user->name)[0],
            'username' => $user->telegramUser->username,
        ];

        return [
            'X-TELEGRAM-USER-DATA' => json_encode($telegramData),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Make authenticated API request
     */
    protected function authenticatedJson(string $method, string $uri, User $user, array $data = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        $headers = array_merge($this->getTelegramHeaders($user), $headers);

        return $this->json($method, $uri, $data, $headers);
    }

    /**
     * Create test database triggers
     */
    private function createTestDatabaseTriggers(): void
    {
        // Create update trigger function for responses table
        DB::statement("
            CREATE OR REPLACE FUNCTION update_responses_updated_at()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            $$ language 'plpgsql';
        ");
    }

    /**
     * Assert JSON response structure matches expected structure
     */
    protected function assertJsonStructureExact(array $expectedStructure, array $actualData, string $path = ''): void
    {
        foreach ($expectedStructure as $key => $value) {
            $currentPath = $path ? "$path.$key" : $key;

            $this->assertArrayHasKey($key, $actualData, "Missing key '$currentPath' in response");

            if (is_array($value)) {
                $this->assertIsArray($actualData[$key], "Expected array at '$currentPath'");
                $this->assertJsonStructureExact($value, $actualData[$key], $currentPath);
            }
        }
    }

    /**
     * Mock external HTTP responses
     */
    protected function mockExternalServices(): void
    {
        // Mock Telegram API responses
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
            'nominatim.openstreetmap.org/*' => Http::response([
                ['display_name' => 'Tashkent, Uzbekistan'],
                ['display_name' => 'Samarkand, Uzbekistan']
            ], 200),
        ]);
    }

    /**
     * Create test data for complex scenarios
     */
    protected function createCompleteTestScenario(): array
    {
        [$sender, $deliverer] = $this->createTwoUsers();

        $sendRequest = \App\Models\SendRequest::factory()
            ->forUser($sender)
            ->withRoute('Tashkent', 'Samarkand')
            ->open()
            ->create();

        $deliveryRequest = \App\Models\DeliveryRequest::factory()
            ->forUser($deliverer)
            ->withRoute('Tashkent', 'Samarkand')
            ->open()
            ->create();

        $response = \App\Models\Response::factory()
            ->forSendRequest($sendRequest, $deliveryRequest)
            ->pending()
            ->create();

        return [
            'sender' => $sender,
            'deliverer' => $deliverer,
            'sendRequest' => $sendRequest,
            'deliveryRequest' => $deliveryRequest,
            'response' => $response,
        ];
    }

    /**
     * Assert response has correct error structure
     */
    protected function assertErrorResponse(\Illuminate\Testing\TestResponse $response, int $statusCode, string $errorMessage = null): void
    {
        $response->assertStatus($statusCode);
        $response->assertJsonStructure(['error']);

        if ($errorMessage) {
            $response->assertJson(['error' => $errorMessage]);
        }
    }

    /**
     * Assert response has pagination structure
     */
    protected function assertPaginatedResponse(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertJsonStructure([
            'data',
            'links' => [
                'first',
                'last',
                'prev',
                'next'
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'per_page',
                'to',
                'total'
            ]
        ]);
    }

    /**
     * Create test environment for chat testing
     */
    protected function createChatTestEnvironment(): array
    {
        [$user1, $user2] = $this->createTwoUsers();

        $chat = \App\Models\Chat::factory()
            ->betweenUsers($user1, $user2)
            ->active()
            ->create();

        $messages = \App\Models\ChatMessage::factory()
            ->count(5)
            ->forChat($chat)
            ->create();

        return [
            'user1' => $user1,
            'user2' => $user2,
            'chat' => $chat,
            'messages' => $messages,
        ];
    }

    /**
     * Assert database has specific records
     */
    protected function assertDatabaseHasModel($model, array $attributes = []): void
    {
        $table = $model->getTable();
        $attributes['id'] = $model->id;

        $this->assertDatabaseHas($table, $attributes);
    }

    /**
     * Assert database count for model
     */
    protected function assertModelCount(string $modelClass, int $expectedCount): void
    {
        $actualCount = $modelClass::count();
        $this->assertEquals(
            $expectedCount,
            $actualCount,
            "Expected {$expectedCount} {$modelClass} records, but found {$actualCount}"
        );
    }
}
