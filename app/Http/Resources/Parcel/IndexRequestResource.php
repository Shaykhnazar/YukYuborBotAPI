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
        $user = $this->user;
        $telegram = $user->telegramUser;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'has_responses' => $this->has_responses ?? false,
            'from_location' => $this->from_location,
            'to_location' => $this->to_location,
            'from_date' => $this->from_date,
            'to_date' => $this->to_date,
            'size_type' => $this->size_type,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'image' => $telegram->image ?? null,
                'requests_count' => $user->sendRequests->count() + $user->deliveryRequests->count(),
            ]
        ];
    }
}
