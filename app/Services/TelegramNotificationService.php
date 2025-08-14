<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    protected string $botToken;

    public function __construct()
    {
        $this->botToken = config('auth.guards.tgwebapp.token');
    }

    /**
     * Send a simple text message
     */
    public function sendMessage(int $chatId, string $text): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        return $this->sendTelegramRequest('sendMessage', $payload);
    }

    /**
     * Send a message with inline keyboard
     */
    public function sendMessageWithKeyboard(int $chatId, string $text, array $keyboard): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        return $this->sendTelegramRequest('sendMessage', $payload);
    }

    /**
     * Answer callback query (removes loading state from buttons)
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        $payload = ['callback_query_id' => $callbackQueryId];

        if ($text) {
            $payload['text'] = $text;
        }

        return $this->sendTelegramRequest('answerCallbackQuery', $payload);
    }

    /**
     * Build inline keyboard for delivery responses
     */
    public function buildDeliveryResponseKeyboard(int $sendRequestId, int $deliveryRequestId, int $sendUserId, int $deliveryUserId): ?array
    {
        $acceptCallback = $this->buildCallbackData([
            'request',
            'delivery',
            'accept',
            $sendRequestId,
            $deliveryRequestId,
            $sendUserId,
            $deliveryUserId
        ]);

        $rejectCallback = $this->buildCallbackData([
            'request',
            'delivery',
            'reject',
            $sendRequestId,
            $deliveryRequestId,
            $sendUserId,
            $deliveryUserId
        ]);

        // Check Telegram's 64-byte limit for callback data
        if (strlen($acceptCallback) > 64 || strlen($rejectCallback) > 64) {
            Log::error('Callback data exceeds Telegram limit', [
                'accept_length' => strlen($acceptCallback),
                'reject_length' => strlen($rejectCallback),
                'send_id' => $sendRequestId,
                'delivery_id' => $deliveryRequestId
            ]);
            return null;
        }

        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'Принять ✅',
                        'callback_data' => $acceptCallback,
                    ],
                    [
                        'text' => 'Отклонить ❌',
                        'callback_data' => $rejectCallback,
                    ]
                ]
            ]
        ];
    }

    /**
     * Build sender response keyboard (for when sender needs to confirm deliverer)
     */
    public function buildSenderResponseKeyboard(int $sendRequestId, int $deliveryRequestId): ?array
    {
        $acceptCallback = $this->buildCallbackData([
            'sender',
            'confirm',
            'accept',
            $sendRequestId,
            $deliveryRequestId
        ]);

        $rejectCallback = $this->buildCallbackData([
            'sender',
            'confirm',
            'reject',
            $sendRequestId,
            $deliveryRequestId
        ]);

        if (strlen($acceptCallback) > 64 || strlen($rejectCallback) > 64) {
            Log::error('Sender callback data exceeds Telegram limit', [
                'accept_length' => strlen($acceptCallback),
                'reject_length' => strlen($rejectCallback)
            ]);
            return null;
        }

        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'Подтвердить ✅',
                        'callback_data' => $acceptCallback,
                    ],
                    [
                        'text' => 'Отклонить ❌',
                        'callback_data' => $rejectCallback,
                    ]
                ]
            ]
        ];
    }

    /**
     * Build callback data string from array
     */
    private function buildCallbackData(array $parts): string
    {
        return implode(':', $parts);
    }

    /**
     * Parse callback data string to array
     */
    public function parseCallbackData(string $callbackData): array
    {
        return explode(':', $callbackData);
    }

    /**
     * Send request to Telegram API
     */
    private function sendTelegramRequest(string $method, array $payload): bool
    {
        $response = Http::withOptions(['verify' => false])
            ->post("https://api.telegram.org/bot{$this->botToken}/{$method}", $payload);

        if ($response->failed()) {
            Log::error("Telegram {$method} request failed", [
                'error' => $response->body(),
                'payload' => $payload
            ]);
            return false;
        }

        return true;
    }

    /**
     * Validate if callback data will fit Telegram limits
     */
    public function validateCallbackDataSize(string $callbackData): bool
    {
        return strlen($callbackData) <= 64;
    }

    /**
     * Get bot token for external use if needed
     */
    public function getBotToken(): string
    {
        return $this->botToken;
    }
}
