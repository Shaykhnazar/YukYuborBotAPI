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
        // Always use the original request owner data (current user's own requests)
        $displayUser = $this->user;
        $telegram = $displayUser->telegramUser;

        // Determine if we should show responder data
        $isResponder = isset($this->responder_user);

        // Calculate type-specific request counts (only closed/completed requests)
        $sendRequestsCount = $displayUser->sendRequests()
            ->whereIn('status', ['completed', 'closed'])
            ->count();
        $deliveryRequestsCount = $displayUser->deliveryRequests()
            ->whereIn('status', ['completed', 'closed'])
            ->count();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'response_status' => $this->response_status ?? null,
            'response_type' => $this->response_type ?? null,
            'has_responses' => in_array($this->status, ['has_responses', 'matched', 'matched_manually']),
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
            'is_responder' => $isResponder,
        ];
    }
}
