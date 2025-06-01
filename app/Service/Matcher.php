<?php

namespace App\Service;

use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Matcher
{
    protected string $botToken;

    public function __construct()
    {
        $this->botToken = config('auth.guards.tgwebapp.token');
    }

    public function matchSendRequest(SendRequest $sendRequest): void
    {
        $matchedDeliveries = DeliveryRequest::where('from_location', $sendRequest->from_location)
            ->where(function($query) use ($sendRequest) {
                $query->where('to_location', $sendRequest->to_location)
                    ->orWhere('to_location', '*');
            })
            ->where(function($query) use ($sendRequest) {
                $query->where('from_date', '<=', $sendRequest->to_date)
                    ->where('to_date', '>=', $sendRequest->from_date);
            })
            ->where(function($query) use ($sendRequest) {
                $query->where('size_type', $sendRequest->size_type)
                    ->orWhere('size_type', 'Не указана')
                    ->orWhere('size_type', null);
            })
            ->where('status', 'open')
            ->where('user_id', '!=', $sendRequest->user_id) // Don't match with self
            ->get();

        foreach ($matchedDeliveries as $delivery) {
            // Create response record in database
            $this->createResponseRecord(
                $sendRequest->user_id,
                $delivery->user_id,
                'send',
                $sendRequest->id,
                $delivery->id
            );

            // Send notification to delivery user
            $this->notifyDeliveryUser($sendRequest, $delivery);
        }
    }

    public function matchDeliveryRequest(DeliveryRequest $deliveryRequest): void
    {
        $matchedSends = SendRequest::where('from_location', $deliveryRequest->from_location)
            ->where(function($query) use ($deliveryRequest) {
                $query->where('to_location', $deliveryRequest->to_location)
                    ->orWhere('to_location', '*');
            })
            ->where(function($query) use ($deliveryRequest) {
                $query->where('from_date', '<=', $deliveryRequest->to_date)
                    ->where('to_date', '>=', $deliveryRequest->from_date);
            })
            ->where(function($query) use ($deliveryRequest) {
                $query->where('size_type', $deliveryRequest->size_type)
                    ->orWhere('size_type', 'Не указана')
                    ->orWhere('size_type', null);
            })
            ->where('status', 'open')
            ->where('user_id', '!=', $deliveryRequest->user_id) // Don't match with self
            ->get();

        foreach ($matchedSends as $send) {
            // Create response record in database
            $this->createResponseRecord(
                $deliveryRequest->user_id,
                $send->user_id,
                'delivery',
                $deliveryRequest->id,
                $send->id
            );

            // Send notification to send user
            $this->notifySenderUser($send, $deliveryRequest);
        }
    }

    /**
     * Create a response record in the database
     */
    private function createResponseRecord(int $userId, int $responderId, string $requestType, int $requestId, int $offerId): void
    {
        // Check if response already exists to avoid duplicates
        $existingResponse = Response::where('user_id', $userId)
            ->where('responder_id', $responderId)
            ->where('request_type', $requestType)
            ->where('request_id', $requestId)
            ->where('offer_id', $offerId)
            ->first();

        if (!$existingResponse) {
            Response::create([
                'user_id' => $userId,
                'responder_id' => $responderId,
                'request_type' => $requestType,
                'request_id' => $requestId,
                'offer_id' => $offerId,
                'status' => 'pending',
                'message' => null
            ]);

            Log::info('Response record created', [
                'user_id' => $userId,
                'responder_id' => $responderId,
                'request_type' => $requestType,
                'request_id' => $requestId,
                'offer_id' => $offerId
            ]);
        }
    }

    protected function notifyDeliveryUser(SendRequest $sendRequest, DeliveryRequest $delivery): void
    {
        $user = $delivery->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of delivery ID {$delivery->id}");
            return;
        }

        $text = "🎉 Поздравляем, по Вашей <b>заявке №{$delivery->id}</b> найден заказ!\n\n";
        $text .= "<b>Вот данные от отправителя посылки:</b>\n";
        $text .= "<b>🛫Город отправления:</b> {$sendRequest->from_location}\n";
        $text .= "<b>🛫Город назначения:</b> {$sendRequest->to_location}\n";
        $text .= "<b>🗓Даты:</b> {$sendRequest->from_date} - {$sendRequest->to_date}\n";
        $text .= "<b>📊Категория посылки:</b> {$sendRequest->size_type}\n\n";

        if ($sendRequest->description != 'Пропустить') {
            $text .= "<b>📜 Дополнительные примечания:</b> {$sendRequest->description}";
        } else {
            $text .= "<b>📜 Дополнительные примечания:</b> Не указаны";
        }

        $acceptCallback = implode(':', [
            'request',
            'delivery',
            'accept',
            $sendRequest->id,
            $delivery->id,
            $sendRequest->user_id,
            $delivery->user_id
        ]);
        $rejectCallback = implode(':', [
            'request',
            'delivery',
            'reject',
            $sendRequest->id,
            $delivery->id,
            $sendRequest->user_id,
            $delivery->user_id
        ]);

        // Проверяем размер callback данных (должен быть ≤ 64 байт)
        if (strlen($acceptCallback) > 64 || strlen($rejectCallback) > 64) {
            Log::error('Callback data too large', [
                'accept_size' => strlen($acceptCallback),
                'reject_size' => strlen($rejectCallback),
                'send_id' => $sendRequest->id,
                'delivery_id' => $delivery->id
            ]);
            $keyboard = null;
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => 'Принять✅',
                            'callback_data' => $acceptCallback,
                        ],
                        [
                            'text' => 'Отклонить❌',
                            'callback_data' => $rejectCallback,
                        ]
                    ]
                ]
            ];
        }

        $payload = [
            'chat_id' => $user->telegramUser->telegram,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode($keyboard);
        }

        $response = Http::withOptions([
            'verify' => false,
        ])->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $payload);

        if ($response->failed()) {
            Log::error('Telegram notification failed', [
                'error' => $response->body(),
                'send_id' => $sendRequest->id,
                'delivery_id' => $delivery->id,
                'accept_callback' => $acceptCallback,
                'reject_callback' => $rejectCallback,
            ]);
        }
    }

    protected function notifySenderUser(SendRequest $sendRequest, DeliveryRequest $delivery): void
    {
        $user = $sendRequest->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of send request ID {$sendRequest->id}");
            return;
        }

        $text = "🎉 Поздравляем, по Вашей <b>посылке №{$sendRequest->id}</b> найден перевозчик!\n\n";
        $text .= "<b>Вот данные от перевозчика:</b>\n";
        $text .= "<b>🛫Город отправления:</b> {$sendRequest->from_location}\n";
        $text .= "<b>🛫Город назначения:</b> {$sendRequest->to_location}\n";
        $text .= "<b>🗓Даты:</b> {$sendRequest->from_date} - {$sendRequest->to_date}\n";
        $text .= "<b>📊Категория посылки:</b> {$sendRequest->size_type}\n\n";

        if ($delivery->description != 'Пропустить') {
            $text .= "<b>📜 Дополнительные примечания перевозчика:</b> {$delivery->description}";
        } else {
            $text .= "<b>📜 Дополнительные примечания перевозчика:</b> Не указаны";
        }

        $acceptCallback = implode(':', [
            'request',
            'sender',
            'accept',
            $sendRequest->id,
            $delivery->id,
            $sendRequest->user_id,
            $delivery->user_id
        ]);
        $rejectCallback = implode(':', [
            'request',
            'sender',
            'reject',
            $sendRequest->id,
            $delivery->id,
            $sendRequest->user_id,
            $delivery->user_id
        ]);

        // Проверяем размер callback данных (должен быть ≤ 64 байт)
        if (strlen($acceptCallback) > 64 || strlen($rejectCallback) > 64) {
            Log::error('Callback data too large for sender notification', [
                'accept_size' => strlen($acceptCallback),
                'reject_size' => strlen($rejectCallback),
                'send_id' => $sendRequest->id,
                'delivery_id' => $delivery->id
            ]);
            $keyboard = null;
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => 'Принять✅',
                            'callback_data' => $acceptCallback,
                        ],
                        [
                            'text' => 'Отклонить❌',
                            'callback_data' => $rejectCallback,
                        ]
                    ]
                ]
            ];
        }

        $payload = [
            'chat_id' => $user->telegramUser->telegram,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode($keyboard);
        }

        $response = Http::withOptions([
            'verify' => false,
        ])->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $payload);

        if ($response->failed()) {
            Log::error('Telegram notification to sender failed', [
                'error' => $response->body(),
                'send_id' => $sendRequest->id,
                'delivery_id' => $delivery->id,
                'accept_callback' => $acceptCallback,
                'reject_callback' => $rejectCallback,
            ]);
        }
    }
}
