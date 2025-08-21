<?php

namespace App\Http\Resources\Parcel;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IndexRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        // For closed/matched requests, show the other party (responder)
        // For open/pending requests, show the request owner
        $displayUser = null;
        $isResponder = isset($this->responder_user);
        
        if ($isResponder && in_array($this->status, ['matched', 'matched_manually', 'closed', 'completed'])) {
            // Closed/matched request with responder - show the other party
            $displayUser = $this->responder_user;
            $telegram = $displayUser->telegramUser ?? null;
        } else {
            // Open/pending request or no responder - show the request owner
            $displayUser = $this->user;
            $telegram = $displayUser->telegramUser;
        }

        // Calculate type-specific request counts (only closed/completed requests)
        $sendRequestsCount = $displayUser->sendRequests()
            ->closed()
            ->count();
        $deliveryRequestsCount = $displayUser->deliveryRequests()
            ->closed()
            ->count();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'response_status' => $this->response_status ?? null,
            'response_type' => $this->response_type ?? null,
            'has_responses' => $this->status === 'has_responses' || in_array($this->status, ['matched', 'matched_manually']) || ($this->response_id !== null),
            'has_reviewed' => $this->has_reviewed ?? false,
            'chat_id' => $this->chat_id ?? null,
            'response_id' => $this->response_id ?? null,
            'from_location' => $this->fromLocation->fullRouteName,
            'to_location' => $this->toLocation->fullRouteName,
            'from_date' => $this->from_date,
            'to_date' => $this->to_date,
            'size_type' => $this->size_type,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'user' => [
                'id' => $displayUser->id,
                'name' => $displayUser->name,
                'image' => $telegram->image ?? null,
                'requests_count' => $this->type === 'delivery' ? $deliveryRequestsCount : $sendRequestsCount,
                'send_requests_count' => $sendRequestsCount,
                'delivery_requests_count' => $deliveryRequestsCount,
            ],
            'responder_user' => $isResponder ? [
                'id' => $this->responder_user->id,
                'name' => $this->responder_user->name,
                'image' => $this->responder_user->telegramUser->image ?? null,
                'send_requests_count' => $this->responder_user->closed_send_requests_count ?? 0,
                'delivery_requests_count' => $this->responder_user->closed_delivery_requests_count ?? 0,
                'requests_count' => $this->type === 'delivery' 
                    ? ($this->responder_user->closed_delivery_requests_count ?? 0) 
                    : ($this->responder_user->closed_send_requests_count ?? 0),
            ] : null,
            'is_responder' => $isResponder,
        ];
    }
}
