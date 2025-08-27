<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function sendAcceptanceNotification(int $userId): void
    {
        $this->sendTelegramNotification(
            $userId,
            "✅ *Отклик принят*\n Отлично! Теперь вы можете обсудить детали доставки напрямую в чате.💬 Перейдите в раздел _входящие_ в приложении, чтобы начать👇🏻"
        );
    }

    public function sendRejectionNotification(int $userId): void
    {
        $this->sendTelegramNotification(
            $userId,
            "❌ *Отклик отклонён*\n Мы продолжим искать совпадения для вас и уведомим о _новом отклике_⏳"
        );
    }

    public function sendResponseNotification(int $userId): void
    {
        $notificationMessage = "🎉 *У вас новый отклик!*\nВаша заявка получила отклик. Откройте раздел _входящие_ в приложении, чтобы посмотреть детали и решить — принять или отклонить👇🏻";

        $this->sendTelegramNotification($userId, $notificationMessage);
    }

    private function sendTelegramNotification(int $userId, string $message): void
    {
        $user = User::with('telegramUser')->find($userId);

        if (!$user || !$user->telegramUser) {
            return;
        }

        $telegramId = $user->telegramUser->telegram;

        $token = config('auth.guards.tgwebapp.token');

        $webAppUrl = config('app.frontend_app_url');

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Открыть PostLink', 'web_app' => ['url' => $webAppUrl]],
                ],
            ],
        ];

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $telegramId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        if ($response->failed()) {
            Log::error('Failed to send Telegram notification', [
                'user_id' => $userId,
                'telegram_id' => $telegramId,
                'response' => $response->body()
            ]);
        }
    }
}
