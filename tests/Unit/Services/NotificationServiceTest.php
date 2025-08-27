<?php

namespace Tests\Unit\Services;

use App\Models\TelegramUser;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationService = new NotificationService();
    }

    public function test_send_acceptance_notification_sends_keyboard()
    {
        // Arrange
        $user = User::factory()->create();
        TelegramUser::factory()->create(['user_id' => $user->id, 'telegram' => '12345']);
        $webAppUrl = config('app.url');
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Открыть PostLink', 'web_app' => ['url' => $webAppUrl]],
                ],
            ],
        ];

        Http::fake();

        // Act
        $this->notificationService->sendAcceptanceNotification($user->id);

        // Assert
        Http::assertSent(function ($request) use ($keyboard) {
            return $request->url() == 'https://api.telegram.org/bot' . config('auth.guards.tgwebapp.token') . '/sendMessage' &&
                   $request['reply_markup'] == json_encode($keyboard);
        });
    }
}
