<?php

namespace App\Services;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Services\Matching\RequestMatchingService;
use App\Services\Matching\ResponseCreationService;
use App\Services\Matching\ResponseStatusService;
use Illuminate\Support\Facades\Log;

class Matcher
{
    public function __construct(
        protected TelegramNotificationService $telegramService,
        protected RequestMatchingService $matchingService,
        protected ResponseCreationService $creationService,
        protected ResponseStatusService $statusService
    ) {}

    public function matchSendRequest(SendRequest $sendRequest): void
    {
        $matchedDeliveries = $this->matchingService->findMatchingDeliveryRequests($sendRequest);

        foreach ($matchedDeliveries as $delivery) {
            $this->creationService->createMatchingResponse(
                $delivery->user_id,        // deliverer receives the match
                $sendRequest->user_id,     // sender offered the match
                'send',                    // type of offer
                $delivery->id,             // deliverer's request ID
                $sendRequest->id          // sender's request ID
            );

            $this->notifyDeliveryUserAboutNewSend($sendRequest, $delivery);
        }

        Log::info('Send request matching completed', [
            'send_request_id' => $sendRequest->id,
            'matches_found' => $matchedDeliveries->count()
        ]);
    }

    public function matchDeliveryRequest(DeliveryRequest $deliveryRequest): void
    {
        $matchedSends = $this->matchingService->findMatchingSendRequests($deliveryRequest);

        foreach ($matchedSends as $send) {
            $this->creationService->createMatchingResponse(
                $deliveryRequest->user_id, // deliverer receives the match notification (always deliverer first!)
                $send->user_id,            // sender offered the match (they created the send request)
                'send',                    // type of offer (send request is being offered to deliverer)
                $deliveryRequest->id,      // deliverer's request ID (receiving request)
                $send->id                  // sender's request ID (offered request)
            );

            $this->notifyDeliveryUserAboutNewSend($send, $deliveryRequest);
        }

        Log::info('Delivery request matching completed', [
            'delivery_request_id' => $deliveryRequest->id,
            'matches_found' => $matchedSends->count()
        ]);
    }

    public function handleUserResponse(int $responseId, int $userId, string $action): bool
    {
        $response = Response::find($responseId);

        if (!$response) {
            Log::warning('Response not found', ['response_id' => $responseId]);
            return false;
        }

        $status = $action === 'accept' ? 'accepted' : 'rejected';
        $updated = $this->statusService->updateUserStatus($response, $userId, $status);

        if (!$updated) {
            Log::warning('Failed to update user status', [
                'response_id' => $responseId,
                'user_id' => $userId,
                'action' => $action
            ]);
            return false;
        }

        Log::info('User response handled successfully', [
            'response_id' => $responseId,
            'user_id' => $userId,
            'action' => $action,
            'overall_status' => $response->fresh()->overall_status
        ]);

        return true;
    }

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
            $text .= "\n\nПроверьте раздел 'Отклики' в приложении для ответа.";
            $this->telegramService->sendMessage($user->telegramUser->telegram, $text);
        }
    }

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
            $text .= "\n\nПроверьте раздел 'Отклики' в приложении для ответа.";
            $this->telegramService->sendMessage($user->telegramUser->telegram, $text);
        }
    }

    private function buildNewSendNotificationText(SendRequest $sendRequest, DeliveryRequest $delivery): string
    {
        $text = "🎉 Поздравляем, по Вашей <b>заявке №{$delivery->id}</b> найден заказ!\n\n";
        $text .= "<b>Вот данные от отправителя посылки:</b>\n";
        $text .= "<b>🛫 Город отправления:</b> {$sendRequest->fromLocation->fullRouteName}\n";
        $text .= "<b>🛬 Город назначения:</b> {$sendRequest->toLocation->fullRouteName}\n";
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

    private function buildExistingSendNotificationText(SendRequest $sendRequest, DeliveryRequest $delivery): string
    {
        $text = "🎉 Найдены посылки для доставки по Вашей заявке!\n\n";
        $text .= "По вашей заявке на доставку найдена посылка:\n";
        $text .= "<b>🛫 Откуда:</b> {$sendRequest->fromLocation->fullRouteName}\n";
        $text .= "<b>🛬 Куда:</b> {$sendRequest->toLocation->fullRouteName}\n";
        $text .= "<b>🗓 Нужно доставить до:</b> {$sendRequest->to_date}\n";
        $text .= "<b>📊 Категория:</b> " . ($sendRequest->size_type ?: 'Не указана') . "\n";
        $text .= "<b>📦 Что везти:</b> {$sendRequest->description}\n";

        if ($sendRequest->price) {
            $text .= "<b>💰 Оплата:</b> {$sendRequest->price} {$sendRequest->currency}\n";
        }

        $text .= "\nХотите взяться за эту доставку?";

        return $text;
    }

}