<?php

namespace App\Services;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use Illuminate\Support\Facades\Log;

class Matcher_Legacy
{
    public function __construct(
        protected TelegramNotificationService $telegramService
    ) {}

    /**
     * When a SEND request is created, find matching DELIVERY requests
     * and create single response that both users can interact with
     */
    public function matchSendRequest(SendRequest $sendRequest): void
    {
        $matchedDeliveries = $this->findMatchingDeliveryRequests($sendRequest);

        foreach ($matchedDeliveries as $delivery) {
            // Create single response that both users can see and interact with
            $this->createSingleResponseRecord(
                $delivery->user_id,        // deliverer receives the match
                $sendRequest->user_id,     // sender offered the match
                'send',                    // type of offer
                $delivery->id,             // deliverer's request ID
                $sendRequest->id          // sender's request ID
            );

            // Notify the DELIVERY user about the SEND request with callback buttons
            $this->notifyDeliveryUserAboutNewSend($sendRequest, $delivery);
        }
    }

    /**
     * When a DELIVERY request is created, find matching SEND requests
     * and create single response that both users can interact with
     */
    public function matchDeliveryRequest(DeliveryRequest $deliveryRequest): void
    {
        $matchedSends = $this->findMatchingSendRequests($deliveryRequest);

        foreach ($matchedSends as $send) {
            // Create single response that both users can see and interact with
            $this->createSingleResponseRecord(
                $send->user_id,            // sender receives the match
                $deliveryRequest->user_id, // deliverer offered the match
                'delivery',                // type of offer
                $send->id,                 // sender's request ID
                $deliveryRequest->id       // deliverer's request ID
            );

            // Notify the DELIVERY user about existing SEND requests
            $this->notifyDeliveryUserAboutExistingSend($send, $deliveryRequest);
        }
    }

    /**
     * Handle user response action (accept/reject) on a matching response
     */
    public function handleUserResponse(int $responseId, int $userId, string $action): bool
    {
        $response = Response::find($responseId);

        if (!$response) {
            Log::warning('Response not found', ['response_id' => $responseId]);
            return false;
        }

        // Update the user's status in the response
        $status = $action === 'accept' ? Response::DUAL_STATUS_ACCEPTED : Response::DUAL_STATUS_REJECTED;
        $updated = $response->updateUserStatus($userId, $status);

        if (!$updated) {
            Log::warning('Failed to update user status', [
                'response_id' => $responseId,
                'user_id' => $userId,
                'action' => $action
            ]);
            return false;
        }

        // Handle notifications and status updates based on the overall status
        $this->handleResponseStatusChange($response, $userId, $action);

        return true;
    }

    /**
     * Find matching delivery requests for a send request
     */
    private function findMatchingDeliveryRequests(SendRequest $sendRequest): \Illuminate\Database\Eloquent\Collection
    {
        return DeliveryRequest::where('from_location_id', $sendRequest->from_location_id)
            ->where('to_location_id', $sendRequest->to_location_id)
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
        return SendRequest::where('from_location_id', $deliveryRequest->from_location_id)
            ->where('to_location_id', $deliveryRequest->to_location_id)
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
     * Create a single response record that both users can interact with
     */
    private function createSingleResponseRecord(int $userId, int $responderId, string $offerType, int $requestId, int $offerId): void
    {
        $response = Response::updateOrCreate(
            [
                'user_id' => $userId,
                'responder_id' => $responderId,
                'offer_type' => $offerType,
                'request_id' => $requestId,
                'offer_id' => $offerId,
            ],
            [
                'response_type' => Response::TYPE_MATCHING,
                'deliverer_status' => Response::DUAL_STATUS_PENDING,
                'sender_status' => Response::DUAL_STATUS_PENDING,
                'overall_status' => Response::OVERALL_STATUS_PENDING,
                'message' => null
            ]
        );

        // Update receiving request status to 'has_responses' when first response is created
        $this->updateReceivingRequestStatusToHasResponses($requestId, $offerId, $offerType);

        Log::info('Single response record created/updated', [
            'user_id' => $userId,
            'responder_id' => $responderId,
            'offer_type' => $offerType,
            'request_id' => $requestId,
            'offer_id' => $offerId,
            'response_id' => $response->id
        ]);
    }

    /**
     * Handle status changes and notifications when response status changes
     */
    private function handleResponseStatusChange(Response $response, int $userId, string $action): void
    {
        $userRole = $response->getUserRole($userId);

        if ($response->isPartiallyAccepted()) {
            // First user accepted, update the offering request status and notify the other user
            $this->updateOfferingRequestStatusOnAcceptance($response, $userId, $userRole);
            $this->notifyOtherUserAboutAcceptance($response, $userId, $userRole);
        } elseif ($response->isFullyAccepted()) {
            // Both users accepted, create chat and finalize
            $this->handleFullAcceptance($response);
        } elseif ($response->isRejected()) {
            // Someone rejected, notify if needed
            $this->handleRejection($response, $userId, $userRole);
        }
    }

    /**
     * Update the offering request status to 'has_responses' when someone accepts
     */
    private function updateOfferingRequestStatusOnAcceptance(Response $response, int $acceptingUserId, string $acceptingUserRole): void
    {
        if ($response->offer_type === 'send') {
            // If SendRequest was offered and deliverer accepted, update SendRequest to 'has_responses'
            if ($acceptingUserRole === 'deliverer') {
                SendRequest::where('id', $response->offer_id)
                    ->where('status', 'open')
                    ->update(['status' => 'has_responses']);

                Log::info('Updated offering SendRequest status to has_responses after deliverer acceptance', [
                    'send_request_id' => $response->offer_id,
                    'accepting_user_id' => $acceptingUserId
                ]);
            }
        } else {
            // If DeliveryRequest was offered and sender accepted, update DeliveryRequest to 'has_responses'
            if ($acceptingUserRole === 'sender') {
                DeliveryRequest::where('id', $response->offer_id)
                    ->where('status', 'open')
                    ->update(['status' => 'has_responses']);

                Log::info('Updated offering DeliveryRequest status to has_responses after sender acceptance', [
                    'delivery_request_id' => $response->offer_id,
                    'accepting_user_id' => $acceptingUserId
                ]);
            }
        }
    }

    /**
     * Update only the receiving request status to 'has_responses' when responses are created
     * The offering request stays 'open' until the other user accepts
     */
    private function updateReceivingRequestStatusToHasResponses(int $requestId, int $offerId, string $offerType): void
    {
        if ($offerType === 'send') {
            // SendRequest is being offered to DeliveryRequest
            // Only update the RECEIVING DeliveryRequest status to 'has_responses'
            // SendRequest stays 'open' until deliverer accepts
            DeliveryRequest::where('id', $requestId)
                ->where('status', 'open')
                ->update(['status' => 'has_responses']);

            Log::info('Updated receiving DeliveryRequest status to has_responses', [
                'delivery_request_id' => $requestId,
                'send_request_id' => $offerId,
                'offer_type' => $offerType
            ]);
        } else {
            // DeliveryRequest is being offered to SendRequest
            // Only update the RECEIVING SendRequest status to 'has_responses'
            // DeliveryRequest stays 'open' until sender accepts
            SendRequest::where('id', $requestId)
                ->where('status', 'open')
                ->update(['status' => 'has_responses']);

            Log::info('Updated receiving SendRequest status to has_responses', [
                'send_request_id' => $requestId,
                'delivery_request_id' => $offerId,
                'offer_type' => $offerType
            ]);
        }
    }

    /**
     * Notify the other user when someone accepts
     */
    private function notifyOtherUserAboutAcceptance(Response $response, int $acceptingUserId, string $acceptingUserRole): void
    {
        // Implementation will depend on your notification system
        // For now, just log the event
        Log::info('User accepted response, notifying other user', [
            'response_id' => $response->id,
            'accepting_user_id' => $acceptingUserId,
            'accepting_role' => $acceptingUserRole,
            'overall_status' => $response->overall_status
        ]);
    }

    /**
     * Handle when both users have accepted the response
     */
    private function handleFullAcceptance(Response $response): void
    {
        // Update request statuses to 'matched'
        $this->updateRequestStatusesToMatched($response);

        Log::info('Response fully accepted by both users', [
            'response_id' => $response->id,
            'overall_status' => $response->overall_status
        ]);
    }

    /**
     * Handle when someone rejects the response
     */
    private function handleRejection(Response $response, int $rejectingUserId, string $rejectingUserRole): void
    {
        Log::info('User rejected response', [
            'response_id' => $response->id,
            'rejecting_user_id' => $rejectingUserId,
            'rejecting_role' => $rejectingUserRole
        ]);
    }

    /**
     * Update request statuses to 'matched' when fully accepted
     */
    private function updateRequestStatusesToMatched(Response $response): void
    {
        if ($response->offer_type === 'send') {
            SendRequest::where('id', $response->offer_id)->update(['status' => 'matched']);
            DeliveryRequest::where('id', $response->request_id)->update(['status' => 'matched']);
        } else {
            DeliveryRequest::where('id', $response->offer_id)->update(['status' => 'matched']);
            SendRequest::where('id', $response->request_id)->update(['status' => 'matched']);
        }
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

    /**
     * Build notification text for deliverer about existing send requests
     */
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

    /**
     * Build notification text for sender about deliverer response
     */
    private function buildSenderNotificationText(SendRequest $sendRequest, DeliveryRequest $delivery): string
    {
        $text = "🎉 Отличные новости! Найден перевозчик для вашей посылки №{$sendRequest->id}!\n\n";
        $text .= "<b>Детали перевозчика:</b>\n";
        $text .= "<b>📍 Маршрут:</b> {$delivery->fromLocation->fullRouteName} → {$delivery->toLocation->fullRouteName}\n";
        $text .= "<b>🗓 Даты поездки:</b> {$delivery->from_date} - {$delivery->to_date}\n";

        if ($delivery->description && $delivery->description !== 'Пропустить') {
            $text .= "<b>📝 Примечания:</b> {$delivery->description}\n";
        }

        $text .= "\n<b>Проверьте раздел 'Отклики' чтобы подтвердить сотрудничество.</b>";

        return $text;
    }
}
