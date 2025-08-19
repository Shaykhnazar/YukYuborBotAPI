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
            "Отлично! Ваш отклик принят. Теперь вы можете общаться в чате."
        );
    }

    public function sendRejectionNotification(int $userId, string $senderName): void
    {
        $this->sendTelegramNotification(
            $userId,
            $senderName,
            "К сожалению, ваш отклик был отклонен."
        );
    }

    public function sendResponseNotification(int $userId, string $responderName, string $message, ?int $amount = null, ?string $currency = null): void
    {
        $notificationMessage = "Пользователь откликнулся на вашу заявку!\n\n";
        $notificationMessage .= "💬 Сообщение: {$message}\n";

        if ($amount && $currency) {
            $notificationMessage .= "💰 Предложенная цена: {$amount} {$currency}\n";
        }

        $notificationMessage .= "\n📱 Проверьте отклики в приложении для ответа.";

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