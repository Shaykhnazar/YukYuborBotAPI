<?php

namespace App\Services\Response;

use App\Enums\ChatStatus;
use App\Enums\DualStatus;
use App\Enums\RequestStatus;
use App\Enums\ResponseStatus;
use App\Enums\ResponseType;
use App\Models\Chat;
use App\Models\User;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\ResponseRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use App\Services\Matcher;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResponseActionService
{
    public function __construct(
        private Matcher $matcher,
        private NotificationService $notificationService,
        private ResponseRepositoryInterface $responseRepository,
        private SendRequestRepositoryInterface $sendRequestRepository,
        private DeliveryRequestRepositoryInterface $deliveryRequestRepository
    ) {}

    /**
     * @param User $user
     * @param int $responseId
     * @return array
     * @throws \Exception
     */
    public function acceptManualResponse(User $user, int $responseId): array
    {
        $response = $this->responseRepository->findWhere([
            'id' => $responseId,
            'user_id' => $user->id,
            'response_type' => ResponseType::MANUAL->value,
            'overall_status' => ResponseStatus::PENDING->value
        ])->first();

        if (!$response) {
            throw new \Exception('Ручной отклик не найден');
        }

        $targetRequest = $this->getTargetRequest($response);
        $responder = User::find($response->responder_id);

        if (!$targetRequest || !$responder) {
            throw new \Exception('Заявка или пользователь не найдены');
        }

        // CRITICAL: Check if request already has an accepted response
        if ($this->hasAcceptedResponse($response->offer_type, $response->offer_id)) {
            throw new \Exception('Эта заявка уже сопоставлена с другим откликом');
        }

        $chat = $this->createOrFindChat($user, $responder, $response);

        $this->responseRepository->update($response->id, [
            'chat_id' => $chat->id,
            'deliverer_status' => DualStatus::ACCEPTED->value,
            'sender_status' => DualStatus::ACCEPTED->value,
            'overall_status' => ResponseStatus::ACCEPTED->value,
        ]);

        $this->updateTargetRequestStatus($targetRequest, RequestStatus::MATCHED_MANUALLY->value);

        // CRITICAL: Close all other pending responses for this request (both manual and matching)
        $this->closePendingResponsesForRequest($response->offer_type, $response->offer_id, $response->id);

        // CRITICAL: Also close any matching responses for this request
        $this->closeMatchingResponsesForRequest($response->offer_type, $response->offer_id, $response->id);

        $this->notificationService->sendAcceptanceNotification($responder->id);

        return ['chat_id' => $chat->id, 'message' => 'Manual response accepted successfully'];
    }

    /**
     * @param User $user
     * @param int $responseId
     * @return string[]
     * @throws \Exception
     */
    public function rejectManualResponse(User $user, int $responseId): array
    {
        $response = $this->responseRepository->findWhere([
            'id' => $responseId,
            'user_id' => $user->id,
            'response_type' => ResponseType::MANUAL->value,
            'overall_status' => ResponseStatus::PENDING->value
        ])->first();

        if (!$response) {
            throw new \Exception('Ручной отклик не найден');
        }

        $this->responseRepository->update($response->id, [
            'overall_status' => ResponseStatus::REJECTED->value,
        ]);

        $this->notificationService->sendRejectionNotification($response->responder_id);

        return ['message' => 'Manual response rejected successfully'];
    }

    /**
     * @param User $user
     * @param int $responseId
     * @return string[]
     * @throws \Exception
     */
    public function cancelManualResponse(User $user, int $responseId): array
    {
        $response = $this->responseRepository->findWhere([
            'id' => $responseId,
            'responder_id' => $user->id, // User must be the responder (who created the response)
            'response_type' => ResponseType::MANUAL->value
        ])->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])->first();

        if (!$response) {
            throw new \Exception('Ручной отклик не найден или не может быть отменен');
        }

        // Store details before deletion
        $offerType = $response->offer_type;
        $offerId = $response->offer_id;

        // Delete the response
        $this->responseRepository->delete($response->id);

        // Update request status after cancellation
        $this->updateRequestStatusAfterManualCancellation($offerType, $offerId);

        return ['message' => 'Manual response cancelled successfully'];
    }

    /**
     * @param User $user
     * @param string $responseId
     * @return string[]
     * @throws \Exception
     */
    public function cancelMatchingResponse(User $user, int $responseId): array
    {
        $response = $this->responseRepository->findWhere([
            'id' => $responseId,
            'response_type' => ResponseType::MATCHING->value
        ])->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])->first();

        if (!$response) {
            throw new \Exception('Отклик не найден или не может быть отменен');
        }

        // Verify user has permission to cancel this response
        if (!($response->user_id == $user->id || $response->responder_id == $user->id)) {
            throw new \Exception('У пользователя нет прав на отмену этого отклика');
        }

        // Store response details before deletion
        $responseOfferType = $response->offer_type;
        $responseOfferId = $response->offer_id;
        $responseRequestId = $response->request_id;

        // Delete the response record
        $this->responseRepository->delete($response->id);

        // Update request statuses
        $this->updateRequestStatusAfterCancellation($responseOfferType, $responseOfferId, $responseRequestId);

        return ['message' => 'Response cancelled successfully'];
    }

    /**
     * @param User $user
     * @param string $responseId
     * @return string[]
     * @throws \Exception
     */
    public function rejectMatchingResponse(User $user, int $responseId): array
    {
        $response = $this->responseRepository->findWhere([
            'id' => $responseId,
            'response_type' => ResponseType::MATCHING->value
        ])->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])->first();

        if (!$response || !$response->canUserTakeAction($user->id)) {
            throw new \Exception('Отклик не найден или не может быть отклонен');
        }

        // Determine user's role in this response
        $userRole = $response->getUserRole($user->id);

        if ($userRole === 'deliverer') {
            // Current user is the deliverer
            return $this->handleDelivererRejection($user, $response->offer_id, $response->request_id);
        } elseif ($userRole === 'sender') {
            // Current user is the sender
            return $this->handleSenderRejection($user, $response->offer_id, $response->request_id);
        }

        throw new \Exception('Неверная роль пользователя для отклонения отклика');
    }

    /**
     * @param User $user
     * @param string $responseId
     * @return array|string[]
     * @throws \Exception
     */
    public function acceptMatchingResponse(User $user, int $responseId): array
    {
        $response = $this->responseRepository->findWhere([
            'id' => $responseId,
            'response_type' => ResponseType::MATCHING->value
        ])->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])->first();

        if (!$response || !$response->canUserTakeAction($user->id)) {
            throw new \Exception('Отклик не найден или не может быть принят');
        }

        // Determine user's role in this response
        $userRole = $response->getUserRole($user->id);

        if ($userRole === 'deliverer') {
            // Current user is the deliverer
            return $this->handleDelivererAcceptance($user, $response->offer_id, $response->request_id);
        } elseif ($userRole === 'sender') {
            // Current user is the sender
            return $this->handleSenderAcceptance($user, $response->offer_id, $response->request_id);
        }

        throw new \Exception('Неверная роль пользователя для принятия отклика');
    }

    /**
     * Handle deliverer accepting a send request
     *
     * @param User $deliverer
     * @param int $sendRequestId
     * @param int $deliveryRequestId
     * @return array|string[]
     * @throws \Exception
     */
    private function handleDelivererAcceptance(User $deliverer, int $sendRequestId, int $deliveryRequestId): array
    {
        $sendRequest = $this->sendRequestRepository->find($sendRequestId);
        $deliveryRequest = $this->deliveryRequestRepository->find($deliveryRequestId);

        if (!$sendRequest || !$deliveryRequest) {
            throw new \Exception('Заявка не найдена');
        }

        // CRITICAL: Check if either request already has an accepted response
        if ($this->hasAcceptedResponse('send', $sendRequestId) ||
            $this->hasAcceptedResponse('delivery', $deliveryRequestId)) {
            throw new \Exception('Одна из этих заявок уже сопоставлена с другим откликом');
        }

        // CRITICAL: Check if this send request already has a partial response from another deliverer
        if ($this->hasPartialResponseForSendRequest($sendRequestId)) {
            throw new \Exception('Эта заявка на отправку уже принимается другим курьером. Дождитесь его решения или выберите другую заявку.');
        }

        // CRITICAL: For deliverer, check if they already have a partial response pending
        if ($this->hasPartialResponseForDeliverer($deliveryRequestId, $deliverer->id)) {
            throw new \Exception('Пожалуйста, дождитесь ответа отправителя на ваш первый отклик перед принятием других откликов');
        }

        // CRITICAL: Check if the send request has an accepted manual response
        if ($this->hasAcceptedManualResponse('send', $sendRequestId)) {
            throw new \Exception('Эта заявка уже принята через ручной отклик');
        }

        $response = $this->responseRepository->findMatchingResponse($sendRequestId, $deliveryRequestId);

        if (!$response || !$response->canUserTakeAction($deliverer->id)) {
            throw new \Exception('Отклик не найден или не может быть принят');
        }

        $result = $this->matcher->handleUserResponse($response->id, $deliverer->id, 'accept');

        if (!$result) {
            throw new \Exception('Не удалось обработать принятие');
        }

        $response = $this->responseRepository->find($response->id);

        if ($response->overall_status === ResponseStatus::ACCEPTED->value) {
            // CRITICAL: Close all other pending responses for both requests (only when fully accepted)
            $this->closePendingResponsesForRequest('send', $sendRequestId, $response->id);
            $this->closePendingResponsesForRequest('delivery', $deliveryRequestId, $response->id);

            // CRITICAL: Close ALL other responses to the deliverer's request (from other senders)
            $this->closeAllResponsesForDelivererRequest($deliveryRequestId, $response->id);

            return ['message' => 'Both users accepted - partnership confirmed!', 'chat_id' => $response->chat_id];
        } else {
            // Deliverer accepted, now in partial state - DO NOT close other responses yet
            return ['message' => 'Response sent to sender for confirmation', 'status' => 'partial'];
        }
    }

    /**
     * Handle sender accepting a delivery request
     *
     * @param User $sender
     * @param int $sendRequestId
     * @param int $deliveryRequestId
     * @return array
     * @throws \Throwable
     */
    private function handleSenderAcceptance(User $sender, int $sendRequestId, int $deliveryRequestId): array
    {
        $sendRequest = $this->sendRequestRepository->find($sendRequestId);
        $deliveryRequest = $this->deliveryRequestRepository->find($deliveryRequestId);

        if (!$sendRequest || !$deliveryRequest) {
            throw new \Exception('Заявка не найдена');
        }

        // CRITICAL: Check if either request already has an accepted response
        if ($this->hasAcceptedResponse('send', $sendRequestId) ||
            $this->hasAcceptedResponse('delivery', $deliveryRequestId)) {
            throw new \Exception('Одна из этих заявок уже сопоставлена с другим откликом');
        }

        // CRITICAL: Check if the send request has an accepted manual response
        if ($this->hasAcceptedManualResponse('send', $sendRequestId)) {
            throw new \Exception('Эта заявка уже принята через ручной отклик');
        }

        $response = $this->responseRepository->findMatchingResponse($sendRequestId, $deliveryRequestId);

        if (!$response || !$response->canUserTakeAction($sender->id)) {
            throw new \Exception('Отклик не найден или не может быть принят');
        }

        DB::beginTransaction();

        try {
            $result = $this->matcher->handleUserResponse($response->id, $sender->id, 'accept');

            if (!$result) {
                throw new \Exception('Не удалось обработать принятие');
            }

            $response = $this->responseRepository->find($response->id);

            if ($response->overall_status === ResponseStatus::ACCEPTED->value) {
                $this->sendRequestRepository->updateStatus($sendRequestId, RequestStatus::MATCHED->value);
                $this->deliveryRequestRepository->updateStatus($deliveryRequestId, RequestStatus::MATCHED->value);
//                $sender->decrement('links_balance', 1);

                // CRITICAL: Close all other pending responses for both requests
                $this->closePendingResponsesForRequest('send', $sendRequestId, $response->id);
                $this->closePendingResponsesForRequest('delivery', $deliveryRequestId, $response->id);

                // CRITICAL: Close ALL other responses to the deliverer's request (from other senders)
                $this->closeAllResponsesForDelivererRequest($deliveryRequestId, $response->id);

                DB::commit();

                return [
                    'message' => 'Partnership confirmed successfully!',
                    'chat_id' => $response->chat_id,
                    'status' => 'accepted'
                ];
            }

            DB::commit();

            return [
                'message' => 'Sender acceptance recorded. Waiting for deliverer confirmation.',
                'status' => 'partial',
                'overall_status' => $response->overall_status
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * @param $response
     * @return Model|null
     */
    private function getTargetRequest($response)
    {
        return $response->offer_type === 'send'
            ? $this->sendRequestRepository->find($response->offer_id)
            : $this->deliveryRequestRepository->find($response->offer_id);
    }

    /**
     * @param $targetRequest
     * @param string $status
     * @return void
     */
    private function updateTargetRequestStatus($targetRequest, string $status): void
    {
        if ($targetRequest instanceof \App\Models\SendRequest) {
            $this->sendRequestRepository->updateStatus($targetRequest->id, $status);
        } else {
            $this->deliveryRequestRepository->updateStatus($targetRequest->id, $status);
        }
    }

    /**
     * @param User $user
     * @param User $responder
     * @param $response
     * @return Chat
     */
    private function createOrFindChat(User $user, User $responder, $response): Chat
    {
        $chat = Chat::where(function($query) use ($user, $responder) {
            $query->where('sender_id', $user->id)
                ->where('receiver_id', $responder->id)
                ->orWhere('sender_id', $responder->id)
                ->where('receiver_id', $user->id);
        })->first();

        if (!$chat) {
            $chat = Chat::create([
                'sender_id' => $user->id,
                'receiver_id' => $responder->id,
                'send_request_id' => $response->offer_type === 'send' ? $response->offer_id : null,
                'delivery_request_id' => $response->offer_type === 'delivery' ? $response->offer_id : null,
                'status' => ChatStatus::ACTIVE->value,
            ]);
        } else {
            $chat->update(['status' => ChatStatus::ACTIVE->value]);
        }

        return $chat;
    }

    /**
     * @param string $offerType
     * @param int $offerId
     * @return void
     */
    private function updateRequestStatusAfterManualCancellation(string $offerType, int $offerId): void
    {
        $targetRequest = $offerType === 'send'
            ? $this->sendRequestRepository->find($offerId)
            : $this->deliveryRequestRepository->find($offerId);

        if (!$targetRequest) {
            return;
        }

        // Check if there are any remaining active manual responses
        $responses = $this->responseRepository->findWhere([
            'offer_id' => $offerId,
            'offer_type' => $offerType,
            'response_type' => ResponseType::MANUAL->value
        ]);

        $hasOtherResponses = $responses->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])->isNotEmpty();

        // Update status based on remaining responses
        $newStatus = $hasOtherResponses ? RequestStatus::HAS_RESPONSES->value : RequestStatus::OPEN->value;
        $this->updateTargetRequestStatus($targetRequest, $newStatus);
    }

    /**
     * @param string $offerType
     * @param int $offerId
     * @param int $requestId
     * @return void
     */
    private function updateRequestStatusAfterCancellation(string $offerType, int $offerId, int $requestId): void
    {
        // Update the offer request status
        if ($offerType === 'send') {
            $sendRequest = $this->sendRequestRepository->find($offerId);
            if ($sendRequest && $sendRequest->status !== RequestStatus::OPEN->value) {
                // Check if there are any remaining active responses
                $responses = $this->responseRepository->findWhere([
                    'offer_id' => $offerId,
                    'offer_type' => 'send'
                ]);
                $hasOtherResponses = $responses->whereIn('overall_status', ['pending', 'waiting', 'responded'])->isNotEmpty();

                $newStatus = $hasOtherResponses ? RequestStatus::HAS_RESPONSES->value : RequestStatus::OPEN->value;
                $this->sendRequestRepository->updateStatus($offerId, $newStatus);
            }
        } elseif ($offerType === 'delivery') {
            $deliveryRequest = $this->deliveryRequestRepository->find($offerId);
            if ($deliveryRequest && $deliveryRequest->status !== RequestStatus::OPEN->value) {
                // Check if there are any remaining active responses
                $responses = $this->responseRepository->findWhere([
                    'offer_id' => $offerId,
                    'offer_type' => 'delivery'
                ]);
                $hasOtherResponses = $responses->whereIn('overall_status', ['pending', 'waiting', 'responded'])->isNotEmpty();

                $newStatus = $hasOtherResponses ? RequestStatus::HAS_RESPONSES->value : RequestStatus::OPEN->value;
                $this->deliveryRequestRepository->updateStatus($offerId, $newStatus);
            }
        }

        // Update the main request status
        if ($offerType === 'send') {
            $deliveryRequest = $this->deliveryRequestRepository->find($requestId);
            if ($deliveryRequest && $deliveryRequest->status !== RequestStatus::OPEN->value) {
                $responses = $this->responseRepository->findWhere([
                    'request_id' => $requestId,
                    'offer_type' => 'send'
                ]);
                $hasOtherResponses = $responses->whereIn('overall_status', ['pending', 'waiting', 'responded'])->isNotEmpty();

                $newStatus = $hasOtherResponses ? RequestStatus::HAS_RESPONSES->value : RequestStatus::OPEN->value;
                $this->deliveryRequestRepository->updateStatus($requestId, $newStatus);
            }
        } elseif ($offerType === 'delivery') {
            $sendRequest = $this->sendRequestRepository->find($requestId);
            if ($sendRequest && $sendRequest->status !== RequestStatus::OPEN->value) {
                $responses = $this->responseRepository->findWhere([
                    'request_id' => $requestId,
                    'offer_type' => 'delivery'
                ]);
                $hasOtherResponses = $responses->whereIn('overall_status', ['pending', 'waiting', 'responded'])->isNotEmpty();

                $newStatus = $hasOtherResponses ? RequestStatus::HAS_RESPONSES->value : RequestStatus::OPEN->value;
                $this->sendRequestRepository->updateStatus($requestId, $newStatus);
            }
        }
    }

    /**
     * @param User $deliverer
     * @param int $sendRequestId
     * @param int $deliveryRequestId
     * @return string[]
     * @throws \Exception
     */
    private function handleDelivererRejection(User $deliverer, int $sendRequestId, int $deliveryRequestId): array
    {
        // Find the response where deliverer is the user (they received the notification)
        $response = $this->responseRepository->findWhere([
            'user_id' => $deliverer->id,
            'offer_type' => 'send',
            'request_id' => $deliveryRequestId,
            'offer_id' => $sendRequestId,
            'overall_status' => ResponseStatus::PENDING->value
        ])->first();

        if (!$response) {
            throw new \Exception('Отклик не найден или не может быть отклонен');
        }

        // CRITICAL FIX: Use transaction to ensure atomic response rejection and request status update
        DB::beginTransaction();

        try {
            // Update the response to rejected
            $response->updateUserStatus($deliverer->id, DualStatus::REJECTED->value);

            // Reset delivery request status if no other active responses exist
            $this->updateRequestStatusAfterRejection('send', $deliveryRequestId, $response->id);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // Optional: Notify sender that deliverer rejected
//        $this->notificationService->sendRejectionNotification(
//            $response->responder_id,
//        );

        return ['message' => 'Send request rejected successfully'];
    }

    /**
     * @param User $sender
     * @param int $sendRequestId
     * @param int $deliveryRequestId
     * @return string[]
     * @throws \Exception
     */
    private function handleSenderRejection(User $sender, int $sendRequestId, int $deliveryRequestId): array
    {
        // Find the response where sender is involved (either as user or having a waiting status)
        $response = $this->responseRepository->findMatchingResponse($sendRequestId, $deliveryRequestId);

        if (!$response || $response->overall_status !== ResponseStatus::PARTIAL->value) {
            throw new \Exception('Отклик не найден или не может быть отклонен');
        }

        // Verify the sender has permission to reject
        if ($response->getUserRole($sender->id) !== 'sender') {
            throw new \Exception('У пользователя нет прав на отклонение этого отклика');
        }

        // CRITICAL FIX: Use transaction to ensure atomic response rejection and request status update
        DB::beginTransaction();

        try {
            // Update the response to rejected
            $response->updateUserStatus($sender->id, DualStatus::REJECTED->value);

            // Reset request statuses - for matching responses, the send request should be checked for remaining responses
            // The delivery request should be reset back to open since the partial match was rejected
            $this->updateSendRequestStatusAfterRejection($sendRequestId, $response->id);
            $this->updateDeliveryRequestStatusAfterRejection($deliveryRequestId, $response->id);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // IMPORTANT: When sender rejects partial response, deliverer can now accept other responses
        // No additional logic needed - the rejection clears the partial state

        // Optional: Notify deliverer that sender rejected
        $delivererUser = $response->getDelivererUser();
        if ($delivererUser) {
            $this->notificationService->sendRejectionNotification(
                $delivererUser->id,
            );
        }

        return ['message' => 'Delivery request rejected successfully'];
    }

    /**
     * @param string $offerType
     * @param int $requestId
     * @param int $rejectedResponseId
     * @return void
     */
    private function updateRequestStatusAfterRejection(string $offerType, int $requestId, int $rejectedResponseId): void
    {
        if ($offerType === 'send') {
            // Check if delivery request has other active responses
            $responses = $this->responseRepository->findWhere([
                'request_id' => $requestId,
                'offer_type' => 'send'
            ]);
            $hasOtherResponses = $responses->where('id', '!=', $rejectedResponseId)
              ->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
              ->isNotEmpty();

            $newStatus = $hasOtherResponses ? RequestStatus::HAS_RESPONSES->value : RequestStatus::OPEN->value;
            $this->deliveryRequestRepository->updateStatus($requestId, $newStatus);
        } else {
            // Check if send request has other active responses
            $responses = $this->responseRepository->findWhere([
                'request_id' => $requestId,
                'offer_type' => 'delivery'
            ]);
            $hasOtherResponses = $responses->where('id', '!=', $rejectedResponseId)
              ->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
              ->isNotEmpty();

            $newStatus = $hasOtherResponses ? RequestStatus::HAS_RESPONSES->value : RequestStatus::OPEN->value;
            $this->sendRequestRepository->updateStatus($requestId, $newStatus);
        }
    }

    /**
     * Update send request status after sender rejects a matching response
     *
     * @param int $sendRequestId
     * @param int $rejectedResponseId
     * @return void
     */
    private function updateSendRequestStatusAfterRejection(int $sendRequestId, int $rejectedResponseId): void
    {
        // Check if send request has other active responses (both manual and matching)
        // IMPORTANT: We need to fetch fresh data to include the just-rejected response in our check
        $activeResponses = $this->responseRepository->findWhere([
            'offer_id' => $sendRequestId,
            'offer_type' => 'send'
        ])->where('id', '!=', $rejectedResponseId)
          ->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value]);

        // CRITICAL: Also check for any manual responses that might still be pending
        $activeManualResponses = $this->responseRepository->findWhere([
            'offer_id' => $sendRequestId,
            'offer_type' => 'send',
            'response_type' => 'manual'
        ])->whereIn('overall_status', [ResponseStatus::PENDING->value]);

        $hasActiveResponses = $activeResponses->isNotEmpty() || $activeManualResponses->isNotEmpty();

        $newStatus = $hasActiveResponses
            ? RequestStatus::HAS_RESPONSES->value
            : RequestStatus::OPEN->value;

        Log::info('Updating send request status after sender rejection', [
            'send_request_id' => $sendRequestId,
            'rejected_response_id' => $rejectedResponseId,
            'active_matching_responses' => $activeResponses->count(),
            'active_manual_responses' => $activeManualResponses->count(),
            'new_status' => $newStatus
        ]);

        $this->sendRequestRepository->updateStatus($sendRequestId, $newStatus);
    }

    /**
     * Update delivery request status after sender rejects a matching response
     *
     * @param int $deliveryRequestId
     * @param int $rejectedResponseId
     * @return void
     */
    private function updateDeliveryRequestStatusAfterRejection(int $deliveryRequestId, int $rejectedResponseId): void
    {
        // Check if delivery request has other active responses
        $activeResponses = $this->responseRepository->findWhere([
            'request_id' => $deliveryRequestId,
            'offer_type' => 'send'
        ])->where('id', '!=', $rejectedResponseId)
          ->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value]);

        $newStatus = $activeResponses->isNotEmpty()
            ? RequestStatus::HAS_RESPONSES->value
            : RequestStatus::OPEN->value;

        $this->deliveryRequestRepository->updateStatus($deliveryRequestId, $newStatus);
    }

    /**
     * Here we update both request status after rejection as sender (DEPRECATED - use specific methods above)
     * First we need to check if there are any other responses for the send request
     * If there are no other responses, we update the request status to open
     * If there are other responses, we update the request status to has responses
     *
     * @param string $offerType
     * @param int $requestId
     * @param int $rejectedResponseId
     * @return void
     */
    private function updateRequestStatusAfterRejectionAsSender(string $offerType, int $requestId, int $rejectedResponseId): void
    {
        // $offerType send or delivery
        // $requestId always send request id or delivery request id
        // $rejectedResponseId always response id

        if ($offerType === 'send') {
            // Check if delivery request has other active responses
            $responses = $this->responseRepository->findWhere([
                'offer_id' => $requestId,
                'offer_type' => 'send'
            ]);
            $hasOtherResponses = $responses->where('id', '!=', $rejectedResponseId)
              ->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
              ->isNotEmpty();

            $newStatus = $hasOtherResponses ? RequestStatus::HAS_RESPONSES->value : RequestStatus::OPEN->value;
            $this->sendRequestRepository->updateStatus($requestId, $newStatus);
        } else {
            // Check if send request has other active responses
            $responses = $this->responseRepository->findWhere([
                'request_id' => $requestId,
                'offer_type' => 'send' // Here we use always send because on matching type of responses we can get the right request
            ]);
            $hasOtherResponses = $responses->where('id', '!=', $rejectedResponseId)
              ->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
              ->isNotEmpty();

            $newStatus = $hasOtherResponses ? RequestStatus::HAS_RESPONSES->value : RequestStatus::OPEN->value;
            $this->deliveryRequestRepository->updateStatus($requestId, $newStatus);
        }
    }

    /**
     * Check if a request already has an accepted response
     *
     * @param string $offerType
     * @param int $requestId
     * @return bool
     */
    private function hasAcceptedResponse(string $offerType, int $requestId): bool
    {
        $responses = $this->responseRepository->findWhere([
            'offer_type' => $offerType,
            'offer_id' => $requestId,
            'overall_status' => ResponseStatus::ACCEPTED->value
        ]);

        return $responses->isNotEmpty();
    }

    /**
     * Check if a request already has an accepted manual response
     *
     * @param string $offerType
     * @param int $requestId
     * @return bool
     */
    private function hasAcceptedManualResponse(string $offerType, int $requestId): bool
    {
        $responses = $this->responseRepository->findWhere([
            'offer_type' => $offerType,
            'offer_id' => $requestId,
            'response_type' => ResponseType::MANUAL->value,
            'overall_status' => ResponseStatus::ACCEPTED->value
        ]);

        return $responses->isNotEmpty();
    }

    /**
     * Check if there's already a partial response for this send request from any deliverer
     * This prevents multiple deliverers from accepting the same send request simultaneously
     *
     * @param int $sendRequestId
     * @return bool
     */
    private function hasPartialResponseForSendRequest(int $sendRequestId): bool
    {
        $partialResponses = $this->responseRepository->findWhere([
            'offer_id' => $sendRequestId,
            'offer_type' => 'send',
            'overall_status' => ResponseStatus::PARTIAL->value,
            'response_type' => ResponseType::MATCHING->value
        ]);

        return $partialResponses->isNotEmpty();
    }

    /**
     * Check if deliverer already has a partial response for their delivery request
     * This prevents deliverer from accepting multiple responses while waiting for sender
     *
     * @param int $deliveryRequestId
     * @param int $delivererId
     * @return bool
     */
    private function hasPartialResponseForDeliverer(int $deliveryRequestId, int $delivererId): bool
    {
        $partialResponses = $this->responseRepository->findWhere([
            'request_id' => $deliveryRequestId,
            'overall_status' => ResponseStatus::PARTIAL->value,
            'response_type' => ResponseType::MATCHING->value
        ]);

        // Check if any of these partial responses involve this deliverer
        foreach ($partialResponses as $response) {
            if ($response->getUserRole($delivererId) === 'deliverer') {
                return true;
            }
        }

        return false;
    }

    /**
     * Close all pending responses for a specific request except the accepted one
     *
     * @param string $offerType
     * @param int $requestId
     * @param int $acceptedResponseId
     * @return void
     */
    private function closePendingResponsesForRequest(string $offerType, int $requestId, int $acceptedResponseId): void
    {
        $pendingResponses = $this->responseRepository->findWhere([
            'offer_type' => $offerType,
            'offer_id' => $requestId
        ])->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
          ->where('id', '!=', $acceptedResponseId);

        foreach ($pendingResponses as $pendingResponse) {
            $this->responseRepository->update($pendingResponse->id, [
                'overall_status' => ResponseStatus::REJECTED->value,
                'deliverer_status' => DualStatus::REJECTED->value,
                'sender_status' => DualStatus::REJECTED->value,
                'updated_at' => now()
            ]);

            // Notify users that their response was automatically rejected due to another acceptance
//            if ($pendingResponse->response_type === ResponseType::MANUAL->value) {
//                // For manual responses, notify the responder
//                $this->notificationService->sendRejectionNotification($pendingResponse->responder_id);
//            } else {
//                // For matching responses, notify both users if they haven't been rejected yet
//                $delivererUser = $pendingResponse->getDelivererUser();
//                $senderUser = $pendingResponse->getSenderUser();
//
//                if ($delivererUser) {
//                    $this->notificationService->sendRejectionNotification($delivererUser->id);
//                }
//                if ($senderUser) {
//                    $this->notificationService->sendRejectionNotification($senderUser->id);
//                }
//            }
        }
    }

    /**
     * Close matching responses for a request when manual response is accepted
     * This prevents matching responses from being accepted after manual acceptance
     *
     * @param string $offerType
     * @param int $requestId
     * @param int $acceptedResponseId
     * @return void
     */
    private function closeMatchingResponsesForRequest(string $offerType, int $requestId, int $acceptedResponseId): void
    {
        $matchingResponses = $this->responseRepository->findWhere([
            'offer_type' => $offerType,
            'offer_id' => $requestId,
            'response_type' => ResponseType::MATCHING->value
        ])->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
          ->where('id', '!=', $acceptedResponseId);

        foreach ($matchingResponses as $matchingResponse) {
            $this->responseRepository->update($matchingResponse->id, [
                'overall_status' => ResponseStatus::REJECTED->value,
                'deliverer_status' => DualStatus::REJECTED->value,
                'sender_status' => DualStatus::REJECTED->value,
                'updated_at' => now()
            ]);

            // Optional: Notify users that their matching response was automatically closed
            // due to manual response acceptance
        }
    }

    /**
     * Close ALL responses to a deliverer's request from other senders
     * This ensures deliverer can only work with one matched sender
     *
     * @param int $deliveryRequestId
     * @param int $acceptedResponseId
     * @return void
     */
    private function closeAllResponsesForDelivererRequest(int $deliveryRequestId, int $acceptedResponseId): void
    {
        // Find all responses where this delivery request is the target (request_id)
        // These are responses from various senders offering their send requests to this deliverer
        $allResponsesToDeliverer = $this->responseRepository->findWhere([
            'request_id' => $deliveryRequestId,
            'response_type' => ResponseType::MATCHING->value
        ])->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
          ->where('id', '!=', $acceptedResponseId);

        foreach ($allResponsesToDeliverer as $response) {
            $this->responseRepository->update($response->id, [
                'overall_status' => ResponseStatus::REJECTED->value,
                'deliverer_status' => DualStatus::REJECTED->value,
                'sender_status' => DualStatus::REJECTED->value,
                'updated_at' => now()
            ]);

            // Notify the sender that their offer is no longer available
//            $senderUser = $response->getSenderUser();
//            if ($senderUser) {
//                $this->notificationService->sendRejectionNotification($senderUser->id);
//            }
        }
    }
}
