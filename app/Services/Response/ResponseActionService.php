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
            throw new \Exception('Manual response not found');
        }

        $targetRequest = $this->getTargetRequest($response);
        $responder = User::find($response->responder_id);

        if (!$targetRequest || !$responder) {
            throw new \Exception('Target request or responder not found');
        }

        $chat = $this->createOrFindChat($user, $responder, $response);

        $this->responseRepository->update($response->id, [
            'chat_id' => $chat->id,
            'deliverer_status' => DualStatus::ACCEPTED->value,
            'sender_status' => DualStatus::ACCEPTED->value,
            'overall_status' => ResponseStatus::ACCEPTED->value,
        ]);

        $this->updateTargetRequestStatus($targetRequest, RequestStatus::MATCHED_MANUALLY->value);

        $this->notificationService->sendAcceptanceNotification($responder->id, $user->name);

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
            throw new \Exception('Manual response not found');
        }

        $this->responseRepository->update($response->id, [
            'overall_status' => ResponseStatus::REJECTED->value,
        ]);

        $this->notificationService->sendRejectionNotification($response->responder_id, $user->name);

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
            throw new \Exception('Manual response not found or cannot be cancelled');
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
    public function cancelMatchingResponse(User $user, string $responseId): array
    {
        $parts = explode('_', $responseId);
        if (count($parts) !== 4) {
            throw new \Exception('Invalid response ID');
        }

        $offerType = $parts[0]; // 'send' or 'delivery' 
        $offerId = (int)$parts[1];
        $requestType = $parts[2]; // 'delivery' or 'send'
        $requestId = (int)$parts[3];

        // Find the actual response to verify user's permission
        $response = null;
        if ($offerType === 'send' && $requestType === 'delivery') {
            // Send request offered to delivery request: send_X_delivery_Y
            $response = $this->responseRepository->findMatchingResponse($offerId, $requestId);
        } elseif ($offerType === 'delivery' && $requestType === 'send') {
            // Delivery request offered to send request: delivery_X_send_Y  
            $response = $this->responseRepository->findMatchingResponse($requestId, $offerId);
        }

        if (!$response || !in_array($response->overall_status, ['waiting', 'accepted', 'pending', 'responded', 'partial'])) {
            throw new \Exception('Response not found or cannot be cancelled');
        }

        // Verify user has permission to cancel this response
        if (!($response->user_id == $user->id || $response->responder_id == $user->id)) {
            throw new \Exception('User does not have permission to cancel this response');
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
    public function rejectMatchingResponse(User $user, string $responseId): array
    {
        $parts = explode('_', $responseId);
        if (count($parts) !== 4) {
            throw new \Exception('Invalid response ID');
        }

        $offerType = $parts[0]; // 'send' or 'delivery' 
        $offerId = (int)$parts[1];
        $requestType = $parts[2]; // 'delivery' or 'send'
        $requestId = (int)$parts[3];

        // Find the actual response to determine user's role
        $response = null;
        if ($offerType === 'send' && $requestType === 'delivery') {
            // Send request offered to delivery request: send_X_delivery_Y
            $response = $this->responseRepository->findMatchingResponse($offerId, $requestId);
        } elseif ($offerType === 'delivery' && $requestType === 'send') {
            // Delivery request offered to send request: delivery_X_send_Y  
            $response = $this->responseRepository->findMatchingResponse($requestId, $offerId);
        }

        if (!$response) {
            throw new \Exception('Response not found');
        }

        // Determine user's role in this response
        $userRole = $response->getUserRole($user->id);

        if ($userRole === 'deliverer') {
            // Current user is the deliverer
            if ($offerType === 'send') {
                // Deliverer rejecting send request: send_X_delivery_Y
                return $this->handleDelivererRejection($user, $offerId, $requestId);
            } else {
                // Deliverer rejecting delivery offer: delivery_X_send_Y (less common)
                return $this->handleDelivererRejection($user, $requestId, $offerId);
            }
        } elseif ($userRole === 'sender') {
            // Current user is the sender
            if ($offerType === 'send') {
                // Sender rejecting after deliverer accepted: send_X_delivery_Y  
                return $this->handleSenderRejection($user, $offerId, $requestId);
            } else {
                // Sender rejecting delivery offer: delivery_X_send_Y
                return $this->handleSenderRejection($user, $requestId, $offerId);
            }
        }

        throw new \Exception('Invalid user role for response rejection');
    }

    /**
     * @param User $user
     * @param string $responseId
     * @return array|string[]
     * @throws \Exception
     */
    public function acceptMatchingResponse(User $user, string $responseId): array
    {
        $parts = explode('_', $responseId);
        if (count($parts) !== 4) {
            throw new \Exception('Invalid response ID');
        }

        $offerType = $parts[0]; // 'send' or 'delivery' 
        $offerId = (int)$parts[1];
        $requestType = $parts[2]; // 'delivery' or 'send'
        $requestId = (int)$parts[3];

        // Find the actual response to determine user's role
        $response = null;
        if ($offerType === 'send' && $requestType === 'delivery') {
            // Send request offered to delivery request: send_X_delivery_Y
            $response = $this->responseRepository->findMatchingResponse($offerId, $requestId);
        } elseif ($offerType === 'delivery' && $requestType === 'send') {
            // Delivery request offered to send request: delivery_X_send_Y  
            $response = $this->responseRepository->findMatchingResponse($requestId, $offerId);
        }

        if (!$response || !$response->canUserTakeAction($user->id)) {
            throw new \Exception('Response not found or cannot be accepted');
        }

        // Determine user's role in this response
        $userRole = $response->getUserRole($user->id);

        if ($userRole === 'deliverer') {
            // Current user is the deliverer
            if ($offerType === 'send') {
                // Deliverer accepting send request: send_X_delivery_Y
                return $this->handleDelivererAcceptance($user, $offerId, $requestId);
            } else {
                // Deliverer accepting delivery offer: delivery_X_send_Y (less common)
                return $this->handleDelivererAcceptance($user, $requestId, $offerId);
            }
        } elseif ($userRole === 'sender') {
            // Current user is the sender
            if ($offerType === 'send') {
                // Sender accepting after deliverer accepted: send_X_delivery_Y  
                return $this->handleSenderAcceptance($user, $offerId, $requestId);
            } else {
                // Sender accepting delivery offer: delivery_X_send_Y
                return $this->handleSenderAcceptance($user, $requestId, $offerId);
            }
        }

        throw new \Exception('Invalid user role for response acceptance');
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
            throw new \Exception('Request not found');
        }

        $response = $this->responseRepository->findMatchingResponse($sendRequestId, $deliveryRequestId);

        if (!$response || !$response->canUserTakeAction($deliverer->id)) {
            throw new \Exception('Response not found or cannot be accepted');
        }

        $result = $this->matcher->handleUserResponse($response->id, $deliverer->id, 'accept');

        if (!$result) {
            throw new \Exception('Failed to process acceptance');
        }

        $response = $this->responseRepository->find($response->id);

        return $response->overall_status === ResponseStatus::ACCEPTED->value
            ? ['message' => 'Both users accepted - partnership confirmed!', 'chat_id' => $response->chat_id]
            : ['message' => 'Response sent to sender for confirmation', 'status' => 'partial'];
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
            throw new \Exception('Request not found');
        }

        $response = $this->responseRepository->findMatchingResponse($sendRequestId, $deliveryRequestId);

        if (!$response || !$response->canUserTakeAction($sender->id)) {
            throw new \Exception('Response not found or cannot be accepted');
        }

        DB::beginTransaction();

        try {
            $result = $this->matcher->handleUserResponse($response->id, $sender->id, 'accept');

            if (!$result) {
                throw new \Exception('Failed to process acceptance');
            }

            $response = $this->responseRepository->find($response->id);

            if ($response->overall_status === ResponseStatus::ACCEPTED->value) {
                $this->sendRequestRepository->updateStatus($sendRequestId, RequestStatus::MATCHED->value);
                $this->deliveryRequestRepository->updateStatus($deliveryRequestId, RequestStatus::MATCHED->value);
                $sender->decrement('links_balance', 1);

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
            throw new \Exception('Response not found or cannot be rejected');
        }

        // Update the response to rejected
        $response->updateUserStatus($deliverer->id, DualStatus::REJECTED->value);

        // Reset delivery request status if no other active responses exist
        $this->updateRequestStatusAfterRejection('send', $deliveryRequestId, $response->id);

        // Optional: Notify sender that deliverer rejected
        $this->notificationService->sendRejectionNotification(
            $response->responder_id,
            $deliverer->name,
            'deliverer_rejected_send'
        );

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
            throw new \Exception('Response not found or cannot be rejected');
        }

        // Verify the sender has permission to reject
        if ($response->getUserRole($sender->id) !== 'sender') {
            throw new \Exception('User does not have permission to reject this response');
        }

        // Update the response to rejected
        $response->updateUserStatus($sender->id, DualStatus::REJECTED->value);

        // Reset request statuses
        $this->updateRequestStatusAfterRejection('delivery', $sendRequestId, $response->id);
        $this->updateRequestStatusAfterRejection('delivery', $deliveryRequestId, $response->id);

        // Optional: Notify deliverer that sender rejected
        $delivererUser = $response->getDelivererUser();
        if ($delivererUser) {
            $this->notificationService->sendRejectionNotification(
                $delivererUser->id,
                $sender->name,
                'sender_rejected_delivery'
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
}
