<?php

namespace App\Service;

use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
use Illuminate\Support\Facades\Log;

class Matcher
{
    public function __construct(
        protected TelegramNotificationService $telegramService
    ) {}

    /**
     * When a SEND request is created, find matching DELIVERY requests
     * and notify the DELIVERY users (potential carriers)
     */
    public function matchSendRequest(SendRequest $sendRequest): void
    {
        $matchedDeliveries = $this->findMatchingDeliveryRequests($sendRequest);

        foreach ($matchedDeliveries as $delivery) {
            // Create response for deliverer to see send request
            $this->createResponseRecord(
                $delivery->user_id,        // deliverer will see this
                $sendRequest->user_id,     // sender made the offer
                'send',                    // type of request
                $delivery->id,             // deliverer's request ID
                $sendRequest->id          // sender's request ID
            );

            // Notify the DELIVERY user about the SEND request with callback buttons
            $this->notifyDeliveryUserAboutNewSend($sendRequest, $delivery);
        }
    }

    /**
     * When a DELIVERY request is created, find matching SEND requests
     */
    public function matchDeliveryRequest(DeliveryRequest $deliveryRequest): void
    {
        $matchedSends = $this->findMatchingSendRequests($deliveryRequest);

        foreach ($matchedSends as $send) {
            // Create response for deliverer to see send request
            $this->createResponseRecord(
                $deliveryRequest->user_id, // deliverer will see this
                $send->user_id,            // sender made the offer
                'send',                    // type of request
                $deliveryRequest->id,      // deliverer's request ID
                $send->id                 // sender's request ID
            );

            // Notify the DELIVERY user about existing SEND requests
            $this->notifyDeliveryUserAboutExistingSend($send, $deliveryRequest);
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
            Log::warning('Send or delivery request not found', [
                'send_id' => $sendRequestId,
                'delivery_id' => $deliveryRequestId
            ]);
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
            $this->notifySenderAboutDelivererResponse($sendRequest, $deliveryRequest);
        }

        // Update the original deliverer's response
        $this->updateDelivererResponseStatus($deliveryRequest->user_id, $sendRequest->id, $deliveryRequest->id, $action);
    }

    /**
     * Find matching delivery requests for a send request
     */
    private function findMatchingDeliveryRequests(SendRequest $sendRequest): \Illuminate\Database\Eloquent\Collection
    {
        return DeliveryRequest::where('from_location', $sendRequest->from_location)
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
            ->whereIn('status', ['open', 'has_responses'])
            ->where('user_id', '!=', $sendRequest->user_id)
            ->get();
    }

    /**
     * Find matching send requests for a delivery request
     */
    private function findMatchingSendRequests(DeliveryRequest $deliveryRequest): \Illuminate\Database\Eloquent\Collection
    {
        return SendRequest::where('from_location', $deliveryRequest->from_location)
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
            ->whereIn('status', ['open', 'has_responses'])
            ->where('user_id', '!=', $deliveryRequest->user_id)
            ->get();
    }

    /**
     * Create a response record in the database
     */
    private function createResponseRecord(int $userId, int $responderId, string $requestType, int $requestId, int $offerId, string $status = 'pending'): void
    {
        $response = Response::updateOrCreate(
            [
                'user_id' => $userId,
                'responder_id' => $responderId,
                'request_type' => $requestType,
                'request_id' => $requestId,
                'offer_id' => $offerId,
            ],
            [
                'status' => $status,
                'message' => null
            ]
        );

        // Update request status to 'has_responses' when first response is created
        $this->updateRequestStatusToHasResponses($requestId, $requestType);

        Log::info('Response record created/updated', [
            'user_id' => $userId,
            'responder_id' => $responderId,
            'request_type' => $requestType,
            'request_id' => $requestId,
            'offer_id' => $offerId,
            'status' => $status,
            'response_id' => $response->id
        ]);
    }

    /**
     * Update request status to 'has_responses' when responses are created
     */
    private function updateRequestStatusToHasResponses(int $requestId, string $requestType): void
    {
        if ($requestType === 'send') {
            DeliveryRequest::where('id', $requestId)
                ->where('status', 'open')
                ->update(['status' => 'has_responses']);
        } else {
            SendRequest::where('id', $requestId)
                ->where('status', 'open')
                ->update(['status' => 'has_responses']);
        }
    }
    /**
     * Update deliverer response status
     */
    private function updateDelivererResponseStatus(int $delivererUserId, int $sendRequestId, int $deliveryRequestId, string $action): void
    {
        $status = $action === 'accept' ? 'responded' : 'rejected';

        $updated = Response::where('user_id', $delivererUserId)
            ->where('request_type', 'send')
            ->where('request_id', $deliveryRequestId)
            ->where('offer_id', $sendRequestId)
            ->update(['status' => $status]);

        Log::info('Deliverer response status updated', [
            'deliverer_user_id' => $delivererUserId,
            'send_request_id' => $sendRequestId,
            'delivery_request_id' => $deliveryRequestId,
            'action' => $action,
            'status' => $status,
            'updated_count' => $updated
        ]);
    }

    /**
     * Notify delivery user about new send request with callback buttons
     */
    private function notifyDeliveryUserAboutNewSend(SendRequest $sendRequest, DeliveryRequest $delivery): void
    {
        $user = $delivery->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of delivery ID {$delivery->id}");
            return;
        }

        $text = $this->buildNewSendNotificationText($sendRequest, $delivery);
        $keyboard = $this->telegramService->buildDeliveryResponseKeyboard(
            $sendRequest->id,
            $delivery->id,
            $sendRequest->user_id,
            $delivery->user_id
        );

        if ($keyboard) {
            $this->telegramService->sendMessageWithKeyboard(
                $user->telegramUser->telegram,
                $text,
                $keyboard
            );
        } else {
            // Fallback to simple message if keyboard creation failed
            $text .= "\n\nПроверьте раздел 'Отклики' в приложении для ответа.";
            $this->telegramService->sendMessage($user->telegramUser->telegram, $text);
        }
    }

    /**
     * Notify delivery user about existing send requests with callback buttons
     */
    private function notifyDeliveryUserAboutExistingSend(SendRequest $sendRequest, DeliveryRequest $delivery): void
    {
        $user = $delivery->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of delivery ID {$delivery->id}");
            return;
        }

        $text = $this->buildExistingSendNotificationText($sendRequest, $delivery);
        $keyboard = $this->telegramService->buildDeliveryResponseKeyboard(
            $sendRequest->id,
            $delivery->id,
            $sendRequest->user_id,
            $delivery->user_id
        );

        if ($keyboard) {
            $this->telegramService->sendMessageWithKeyboard(
                $user->telegramUser->telegram,
                $text,
                $keyboard
            );
        } else {
            // Fallback to simple message if keyboard creation failed
            $text .= "\n\nПроверьте раздел 'Отклики' в приложении для ответа.";
            $this->telegramService->sendMessage($user->telegramUser->telegram, $text);
        }
    }

    /**
     * Notify sender when deliverer responds to their request
     */
    private function notifySenderAboutDelivererResponse(SendRequest $sendRequest, DeliveryRequest $delivery): void
    {
        $user = $sendRequest->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of send request ID {$sendRequest->id}");
            return;
        }

        $text = $this->buildSenderNotificationText($sendRequest, $delivery);
        $this->telegramService->sendMessage($user->telegramUser->telegram, $text);
    }

    /**
     * Build notification text for deliverer about new send request
     */
    private function buildNewSendNotificationText(SendRequest $sendRequest, DeliveryRequest $delivery): string
    {
        $text = "🎉 Поздравляем, по Вашей <b>заявке №{$delivery->id}</b> найден заказ!\n\n";
        $text .= "<b>Вот данные от отправителя посылки:</b>\n";
        $text .= "<b>🛫 Город отправления:</b> {$sendRequest->from_location}\n";
        $text .= "<b>🛬 Город назначения:</b> {$sendRequest->to_location}\n";
        $text .= "<b>🗓 Даты:</b> {$sendRequest->from_date} - {$sendRequest->to_date}\n";
        $text .= "<b>📊 Категория посылки:</b> " . ($sendRequest->size_type ?: 'Не указана') . "\n\n";

        if ($sendRequest->description && $sendRequest->description !== 'Пропустить') {
            $text .= "<b>📜 Дополнительные примечания:</b> {$sendRequest->description}\n\n";
        } else {
            $text .= "<b>📜 Дополнительные примечания:</b> Не указаны\n\n";
        }

        if ($sendRequest->price) {
            $text .= "<b>💰 Оплата:</b> {$sendRequest->price} {$sendRequest->currency}\n\n";
        }

        $text .= "Хотите взяться за эту доставку?";

        return $text;
    }

    /**
     * Build notification text for deliverer about existing send requests
     */
    private function buildExistingSendNotificationText(SendRequest $sendRequest, DeliveryRequest $delivery): string
    {
        $text = "🎉 Найдены посылки для доставки по Вашей заявке!\n\n";
        $text .= "По вашей заявке на доставку найдена посылка:\n";
        $text .= "<b>🛫 Откуда:</b> {$sendRequest->from_location}\n";
        $text .= "<b>🛬 Куда:</b> {$sendRequest->to_location}\n";
        $text .= "<b>🗓 Нужно доставить до:</b> {$sendRequest->to_date}\n";
        $text .= "<b>📊 Категория:</b> " . ($sendRequest->size_type ?: 'Не указана') . "\n";
        $text .= "<b>📦 Что везти:</b> {$sendRequest->description}\n";

        if ($sendRequest->price) {
            $text .= "<b>💰 Оплата:</b> {$sendRequest->price} {$sendRequest->currency}\n";
        }

        $text .= "\nХотите взяться за эту доставку?";

        return $text;
    }

    /**
     * Build notification text for sender about deliverer response
     */
    private function buildSenderNotificationText(SendRequest $sendRequest, DeliveryRequest $delivery): string
    {
        $text = "🎉 Отличные новости! Найден перевозчик для вашей посылки №{$sendRequest->id}!\n\n";
        $text .= "<b>Детали перевозчика:</b>\n";
        $text .= "<b>📍 Маршрут:</b> {$delivery->from_location} → {$delivery->to_location}\n";
        $text .= "<b>🗓 Даты поездки:</b> {$delivery->from_date} - {$delivery->to_date}\n";

        if ($delivery->description && $delivery->description !== 'Пропустить') {
            $text .= "<b>📝 Примечания:</b> {$delivery->description}\n";
        }

        $text .= "\n<b>Проверьте раздел 'Отклики' чтобы подтвердить сотрудничество.</b>";

        return $text;
    }
}
