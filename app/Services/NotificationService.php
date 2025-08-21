<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function sendAcceptanceNotification(int $userId, string $senderName): void
    {
        $this->sendTelegramNotification(
            $userId,
            $senderName,
            "✅ *Отклик принят*\n Отлично! Теперь вы можете обсудить детали доставки напрямую в чате.💬 Перейдите в раздел _входящие_ в приложении, чтобы начать👇🏻"
        );
    }

    public function sendRejectionNotification(int $userId, string $senderName): void
    {
        $this->sendTelegramNotification(
            $userId,
            $senderName,
            "❌ *Отклик отклонён*\n Мы продолжим искать совпадения для вас и уведомим о _новом отклике_⏳"
        );
    }

    public function sendResponseNotification(int $userId, string $responderName, string $message, ?int $amount = null, ?string $currency = null): void
    {
        $notificationMessage = "🎉 *У вас новый отклик!*\nВаша заявка получила отклик. Откройте раздел _входящие_ в приложении, чтобы посмотреть детали и решить — принять или отклонить👇🏻";

        $this->sendTelegramNotification($userId, $responderName, $notificationMessage);
    }

    private function sendTelegramNotification(int $userId, string $senderName, string $message): void
    {
        $user = User::with('telegramUser')->find($userId);

        if (!$user || !$user->telegramUser) {
            return;
        }

        $telegramId = $user->telegramUser->telegram;
        $notificationText = "📬 {$message}";

        $token = config('auth.guards.tgwebapp.token');
        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $telegramId,
            'text' => $notificationText,
            'parse_mode' => 'Markdown',
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
