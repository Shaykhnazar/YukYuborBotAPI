<?php

namespace App\Services\Response;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\User;

class ResponseFormatterService
{
    public function formatResponse(Response $response, User $currentUser): ?array
    {
        $otherUser = $response->user_id === $currentUser->id ? $response->responder : $response->user;
        $userRole = $response->getUserRole($currentUser->id);
        $userStatus = $response->getUserStatus($currentUser->id);
        $canAct = $response->canUserTakeAction($currentUser->id);
        $isReceiver = $response->user_id === $currentUser->id;

        if ($response->offer_type === 'send') {
            return $this->formatSendResponse($response, $otherUser, $userRole, $userStatus, $canAct, $isReceiver);
        } elseif ($response->offer_type === 'delivery') {
            return $this->formatDeliveryResponse($response, $otherUser, $userRole, $userStatus, $canAct, $isReceiver);
        }

        return null;
    }

    private function formatSendResponse(
        Response $response,
        User $otherUser,
        string $userRole,
        string $userStatus,
        bool $canAct,
        bool $isReceiver
    ): ?array {
        $sendRequest = SendRequest::with(['fromLocation', 'toLocation'])->find($response->offer_id);
        if (!$sendRequest || $sendRequest->status === 'closed') {
            return null;
        }

        $deliveryRequest = null;
        if ($response->response_type === 'matching') {
            $deliveryRequest = DeliveryRequest::with(['fromLocation', 'toLocation'])->find($response->request_id);
            if (!$deliveryRequest || $deliveryRequest->status === 'closed') {
                return null;
            }
        }

        $responseId = $response->id;

        return [
            'id' => $responseId,
            'type' => 'send',
            'request_id' => $response->request_id,
            'offer_id' => $response->offer_id,
            'chat_id' => $response->chat_id,
            'can_act_on' => $canAct,
            'user' => $this->formatUser($otherUser, $isReceiver, $response->response_type),
            'from_location' => $sendRequest->fromLocation->fullRouteName,
            'to_location' => $sendRequest->toLocation->fullRouteName,
            'from_date' => $sendRequest->from_date,
            'to_date' => $sendRequest->to_date,
            'price' => $this->getPrice($response, $sendRequest),
            'currency' => $this->getCurrency($response, $sendRequest),
            'size_type' => $sendRequest->size_type,
            'description' => $this->getDescription($response, $sendRequest),
            'status' => $response->overall_status,
            'user_status' => $userStatus,
            'user_role' => $userRole,
            'created_at' => $response->created_at,
            'response_type' => $response->response_type === 'manual' ? 'manual' : 'can_deliver',
            'direction' => $isReceiver ? 'received' : 'sent',
            'original_request' => $this->formatOriginalRequest($sendRequest)
        ];
    }

    private function formatDeliveryResponse(
        Response $response,
        User $otherUser,
        string $userRole,
        string $userStatus,
        bool $canAct,
        bool $isReceiver
    ): ?array {
        $deliveryRequest = DeliveryRequest::with(['fromLocation', 'toLocation'])->find($response->offer_id);
        if (!$deliveryRequest || $deliveryRequest->status === 'closed') {
            return null;
        }

        $sendRequest = null;
        if ($response->response_type === 'matching') {
            $sendRequest = SendRequest::with(['fromLocation', 'toLocation'])->find($response->request_id);
            if (!$sendRequest || $sendRequest->status === 'closed') {
                return null;
            }
        }

        $responseId = $response->id;

        return [
            'id' => $responseId,
            'type' => 'delivery',
            'request_id' => $response->request_id,
            'offer_id' => $response->offer_id,
            'chat_id' => $response->chat_id,
            'can_act_on' => $canAct,
            'user' => $this->formatUser($otherUser, $isReceiver, $response->response_type),
            'from_location' => $deliveryRequest->fromLocation->fullRouteName,
            'to_location' => $deliveryRequest->toLocation->fullRouteName,
            'from_date' => $deliveryRequest->from_date,
            'to_date' => $deliveryRequest->to_date,
            'price' => $this->getPrice($response, $deliveryRequest),
            'currency' => $this->getCurrency($response, $deliveryRequest),
            'size_type' => $deliveryRequest->size_type,
            'description' => $this->getDescription($response, $deliveryRequest),
            'status' => $response->overall_status,
            'user_status' => $userStatus,
            'user_role' => $userRole,
            'created_at' => $response->created_at,
            'response_type' => $response->response_type === 'manual' ? 'manual' : 'deliverer_responded',
            'direction' => $isReceiver ? 'received' : 'sent',
            'original_request' => $sendRequest ? $this->formatOriginalRequest($sendRequest) : null
        ];
    }

    private function formatUser(User $user, bool $isReceiver, string $responseType): array
    {
        $requestsCount = $this->calculateRequestsCount($user, $isReceiver, $responseType);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'image' => $user->telegramUser->image ?? null,
            'requests_count' => $requestsCount,
        ];
    }

    private function calculateRequestsCount(User $user, bool $isReceiver, string $responseType): int
    {
        if ($responseType === 'manual') {
            return $isReceiver
                ? $user->deliveryRequests()->where('status', 'closed')->count()
                : $user->sendRequests()->where('status', 'closed')->count();
        }

        return $isReceiver
            ? $user->sendRequests()->where('status', 'closed')->count()
            : $user->deliveryRequests()->where('status', 'closed')->count();
    }

    private function getPrice(Response $response, $request): ?int
    {
        return ($response->response_type === 'manual' && $response->amount)
            ? $response->amount
            : $request->price;
    }

    private function getCurrency(Response $response, $request): ?string
    {
        return ($response->response_type === 'manual' && $response->currency)
            ? $response->currency
            : $request->currency;
    }

    private function getDescription(Response $response, $request): ?string
    {
        return $response->response_type === 'manual'
            ? $response->message
            : $request->description;
    }

    private function formatOriginalRequest($request): ?array
    {
        if (!$request) {
            return null;
        }

        return [
            'from_location' => $request->fromLocation->fullRouteName,
            'to_location' => $request->toLocation->fullRouteName,
            'description' => $request->description,
            'price' => $request->price,
            'currency' => $request->currency,
        ];
    }
}
