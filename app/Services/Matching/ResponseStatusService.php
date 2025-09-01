<?php

namespace App\Services\Matching;

use App\Enums\ChatStatus;
use App\Enums\DualStatus;
use App\Enums\RequestStatus;
use App\Enums\ResponseStatus;
use App\Models\Chat;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use Illuminate\Support\Facades\Log;

class ResponseStatusService
{
    public function updateUserStatus(Response $response, int $userId, string $status): bool
    {
        $userRole = $response->getUserRole($userId);
        
        if ($userRole === 'unknown') {
            return false;
        }

        $updateData = [];
        
        if ($userRole === 'deliverer') {
            $updateData['deliverer_status'] = $status;
        } else {
            $updateData['sender_status'] = $status;
        }

        $updateData['overall_status'] = $this->calculateOverallStatus($response, $updateData);

        $response->update($updateData);

        $this->handleStatusChange($response, $userId, $status);

        // Trigger rebalancing if this was a deliverer acceptance
        if ($status === DualStatus::ACCEPTED->value && $response->response_type === Response::TYPE_MATCHING) {
            $userRole = $response->getUserRole($userId);
            if ($userRole === 'deliverer') {
                // Use app() to resolve the service to avoid circular dependency
                app(ResponseRebalancingService::class)->rebalanceAfterAcceptance($response);
            }
        }

        return true;
    }

    private function calculateOverallStatus(Response $response, array $newData): string
    {
        $delivererStatus = $newData['deliverer_status'] ?? $response->deliverer_status;
        $senderStatus = $newData['sender_status'] ?? $response->sender_status;

        if ($delivererStatus === DualStatus::REJECTED->value || $senderStatus === DualStatus::REJECTED->value) {
            return ResponseStatus::REJECTED->value;
        }

        if ($delivererStatus === DualStatus::ACCEPTED->value && $senderStatus === DualStatus::ACCEPTED->value) {
            return ResponseStatus::ACCEPTED->value;
        }

        if ($delivererStatus === DualStatus::ACCEPTED->value || $senderStatus === DualStatus::ACCEPTED->value) {
            return ResponseStatus::PARTIAL->value;
        }

        return ResponseStatus::PENDING->value;
    }

    private function handleStatusChange(Response $response, int $userId, string $status): void
    {
        if ($status === DualStatus::ACCEPTED->value) {
            $this->handleAcceptance($response, $userId);
        }

        if ($response->overall_status === ResponseStatus::ACCEPTED->value) {
            $this->handleFullAcceptance($response);
        }
    }

    private function handleAcceptance(Response $response, int $acceptingUserId): void
    {
        $userRole = $response->getUserRole($acceptingUserId);

        if ($response->offer_type === 'send' && $userRole === 'deliverer') {
            SendRequest::where('id', $response->offer_id)
                ->where('status', RequestStatus::OPEN->value)
                ->update(['status' => RequestStatus::HAS_RESPONSES->value]);

            Log::info('Updated offering SendRequest status after deliverer acceptance', [
                'send_request_id' => $response->offer_id,
                'accepting_user_id' => $acceptingUserId
            ]);
        } elseif ($response->offer_type === 'delivery' && $userRole === 'sender') {
            DeliveryRequest::where('id', $response->offer_id)
                ->where('status', RequestStatus::OPEN->value)
                ->update(['status' => RequestStatus::HAS_RESPONSES->value]);

            Log::info('Updated offering DeliveryRequest status after sender acceptance', [
                'delivery_request_id' => $response->offer_id,
                'accepting_user_id' => $acceptingUserId
            ]);
        }
    }

    private function handleFullAcceptance(Response $response): void
    {
        // Update request statuses and cross-reference IDs
        if ($response->offer_type === 'send') {
            // Send request offered to delivery request
            $sendRequestId = $response->offer_id;
            $deliveryRequestId = $response->request_id;
            
            SendRequest::where('id', $sendRequestId)
                ->update([
                    'status' => RequestStatus::MATCHED->value,
                    'matched_delivery_id' => $deliveryRequestId
                ]);
            DeliveryRequest::where('id', $deliveryRequestId)
                ->update([
                    'status' => RequestStatus::MATCHED->value,
                    'matched_send_id' => $sendRequestId
                ]);
        } else {
            // Delivery request offered to send request
            $deliveryRequestId = $response->offer_id;
            $sendRequestId = $response->request_id;
            
            DeliveryRequest::where('id', $deliveryRequestId)
                ->update([
                    'status' => RequestStatus::MATCHED->value,
                    'matched_send_id' => $sendRequestId
                ]);
            SendRequest::where('id', $sendRequestId)
                ->update([
                    'status' => RequestStatus::MATCHED->value,
                    'matched_delivery_id' => $deliveryRequestId
                ]);
        }

        // Create chat for matching responses
        $chat = $this->createOrFindChat($response);
        if ($chat) {
            $response->update(['chat_id' => $chat->id]);
            
            Log::info('Chat created for fully accepted matching response', [
                'response_id' => $response->id,
                'chat_id' => $chat->id
            ]);
        }

        Log::info('Response fully accepted, requests marked as matched', [
            'response_id' => $response->id,
            'overall_status' => $response->overall_status,
            'chat_created' => $chat ? true : false,
            'send_request_id' => $response->offer_type === 'send' ? $response->offer_id : $response->request_id,
            'delivery_request_id' => $response->offer_type === 'send' ? $response->request_id : $response->offer_id
        ]);
    }

    /**
     * Create or find existing chat for matching response
     */
    private function createOrFindChat(Response $response): ?Chat
    {
        $delivererUser = $response->getDelivererUser();
        $senderUser = $response->getSenderUser();

        if (!$delivererUser || !$senderUser) {
            Log::warning('Cannot create chat: missing users', [
                'response_id' => $response->id,
                'deliverer_user_id' => $delivererUser?->id,
                'sender_user_id' => $senderUser?->id
            ]);
            return null;
        }

        // Check if chat already exists between these users
        $existingChat = Chat::where(function($query) use ($delivererUser, $senderUser) {
            $query->where('sender_id', $delivererUser->id)
                ->where('receiver_id', $senderUser->id)
                ->orWhere('sender_id', $senderUser->id)
                ->where('receiver_id', $delivererUser->id);
        })->first();

        if ($existingChat) {
            // Reactivate existing chat if needed
            if ($existingChat->status !== ChatStatus::ACTIVE->value) {
                $existingChat->update(['status' => ChatStatus::ACTIVE->value]);
            }
            return $existingChat;
        }

        // Create new chat
        $chat = Chat::create([
            'sender_id' => $senderUser->id,  // Sender initiates the chat
            'receiver_id' => $delivererUser->id,
            'send_request_id' => $response->offer_type === 'send' ? $response->offer_id : $response->request_id,
            'delivery_request_id' => $response->offer_type === 'send' ? $response->request_id : $response->offer_id,
            'status' => ChatStatus::ACTIVE->value,
        ]);

        Log::info('Created new chat for matching response', [
            'response_id' => $response->id,
            'chat_id' => $chat->id,
            'sender_id' => $senderUser->id,
            'receiver_id' => $delivererUser->id
        ]);

        return $chat;
    }
}