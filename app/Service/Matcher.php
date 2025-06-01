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

    /**
     * When a SEND request is created, find matching DELIVERY requests
     * and notify the DELIVERY users (potential carriers)
     */
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
            ->where('user_id', '!=', $sendRequest->user_id)
            ->get();

        foreach ($matchedDeliveries as $delivery) {
            // Create response for deliverer to see send request
            $this->createResponseRecord(
                $delivery->user_id,        // deliverer will see this
                $sendRequest->user_id,     // sender made the offer
                'send',                    // type of request
                $delivery->id,             // deliverer's request ID
                $sendRequest->id          // sender's request ID
            );

            // Notify the DELIVERY user about the SEND request
            $this->notifyDeliveryUser($sendRequest, $delivery);
        }
    }

    /**
     * When a DELIVERY request is created, find matching SEND requests
     */
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
            ->where('user_id', '!=', $deliveryRequest->user_id)
            ->get();

        foreach ($matchedSends as $send) {
            // Create response for deliverer to see send request
            $this->createResponseRecord(
                $deliveryRequest->user_id, // deliverer will see this
                $send->user_id,            // sender made the offer
                'send',                    // type of request
                $deliveryRequest->id,      // deliverer's request ID
                $send->id                 // sender's request ID
            );

            // Notify the DELIVERY user about the SEND request
            $this->notifyDeliveryUserAboutSend($send, $deliveryRequest);
        }
    }

    /**
     * When deliverer responds to a send request, create response for sender
     */
    public function createDelivererResponse(int $sendRequestId, int $deliveryRequestId, string $action): void
    {
        $sendRequest = SendRequest::find($sendRequestId);
        $deliveryRequest = DeliveryRequest::find($deliveryRequestId);

        if (!$sendRequest || !$deliveryRequest) {
            return;
        }

        if ($action === 'accept') {
            // Create response for sender to see deliverer's acceptance
            $this->createResponseRecord(
                $sendRequest->user_id,     // sender will see this
                $deliveryRequest->user_id, // deliverer is responding
                'delivery',                // type of response
                $sendRequest->id,          // sender's request ID
                $deliveryRequest->id,      // deliverer's request ID
                'waiting'                  // waiting for sender's confirmation
            );

            // Notify sender about deliverer's acceptance
            $this->notifySenderAboutDelivererResponse($sendRequest, $deliveryRequest, 'accept');
        }

        // Update the original deliverer's response
        Response::where('user_id', $deliveryRequest->user_id)
            ->where('offer_id', $sendRequest->id)
            ->where('request_id', $deliveryRequest->id)
            ->update(['status' => $action === 'accept' ? 'responded' : 'rejected']);
    }

    /**
     * Create a response record in the database
     */
    private function createResponseRecord(int $userId, int $responderId, string $requestType, int $requestId, int $offerId, string $status = 'pending'): void
    {
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
                'status' => $status,
                'message' => null
            ]);

            Log::info('Response record created', [
                'user_id' => $userId,
                'responder_id' => $responderId,
                'request_type' => $requestType,
                'request_id' => $requestId,
                'offer_id' => $offerId,
                'status' => $status
            ]);
        }
    }

    /**
     * Notify delivery user about send request (when send request is created)
     */
    protected function notifyDeliveryUser(SendRequest $sendRequest, DeliveryRequest $delivery): void
    {
        $user = $delivery->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of delivery ID {$delivery->id}");
            return;
        }

        $text = "🎉 Найдена посылка для доставки!\n\n";
        $text .= "<b>Детали посылки от отправителя:</b>\n";
        $text .= "<b>🛫 Откуда:</b> {$sendRequest->from_location}\n";
        $text .= "<b>🛬 Куда:</b> {$sendRequest->to_location}\n";
        $text .= "<b>🗓 Даты отправки:</b> {$sendRequest->from_date} - {$sendRequest->to_date}\n";
        $text .= "<b>📦 Что отправляем:</b> {$sendRequest->description}\n";
        $text .= "<b>💰 Оплата:</b> {$sendRequest->price} {$sendRequest->currency}\n\n";
        $text .= "Проверьте раздел 'Отклики' в приложении для подробностей.";

        $this->sendTelegramMessage($user->telegramUser->telegram, $text);
    }

    /**
     * Notify delivery user about existing send requests (when delivery request is created)
     */
    protected function notifyDeliveryUserAboutSend(SendRequest $sendRequest, DeliveryRequest $delivery): void
    {
        $user = $delivery->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of delivery ID {$delivery->id}");
            return;
        }

        $text = "🎉 Найдены посылки для доставки!\n\n";
        $text .= "По вашей заявке на доставку найдена посылка:\n";
        $text .= "<b>🛫 Откуда:</b> {$sendRequest->from_location}\n";
        $text .= "<b>🛬 Куда:</b> {$sendRequest->to_location}\n";
        $text .= "<b>🗓 Нужно доставить до:</b> {$sendRequest->to_date}\n";
        $text .= "<b>📦 Что везти:</b> {$sendRequest->description}\n";
        $text .= "<b>💰 Оплата:</b> {$sendRequest->price} {$sendRequest->currency}\n\n";
        $text .= "Посмотрите в разделе 'Отклики' для ответа.";

        $this->sendTelegramMessage($user->telegramUser->telegram, $text);
    }

    /**
     * Notify sender when deliverer responds to their request
     */
    protected function notifySenderAboutDelivererResponse(SendRequest $sendRequest, DeliveryRequest $delivery, string $action): void
    {
        $user = $sendRequest->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of send request ID {$sendRequest->id}");
            return;
        }

        if ($action === 'accept') {
            $text = "🎉 Отличные новости! Найден перевозчик для вашей посылки!\n\n";
            $text .= "<b>Детали перевозчика:</b>\n";
            $text .= "<b>📍 Маршрут:</b> {$delivery->from_location} → {$delivery->to_location}\n";
            $text .= "<b>🗓 Даты поездки:</b> {$delivery->from_date} - {$delivery->to_date}\n";
            if ($delivery->description) {
                $text .= "<b>📝 Примечания:</b> {$delivery->description}\n";
            }
            $text .= "\n<b>Проверьте раздел 'Отклики' чтобы подтвердить сотрудничество.</b>";
        } else {
            $text = "К сожалению, один из перевозчиков отклонил вашу посылку. Мы продолжаем поиск других вариантов.";
        }

        $this->sendTelegramMessage($user->telegramUser->telegram, $text);
    }

    /**
     * Send telegram message
     */
    private function sendTelegramMessage(int $chatId, string $text): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        $response = Http::withOptions(['verify' => false])
            ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $payload);

        if ($response->failed()) {
            Log::error('Telegram notification failed', [
                'error' => $response->body(),
                'chat_id' => $chatId,
            ]);
        }
    }
}
