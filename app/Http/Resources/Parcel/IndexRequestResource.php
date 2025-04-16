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
            'from_location' => $this->from_location,
            'to_location' => $this->to_location,
            'from_date' => $this->from_date,
            'to_date' => $this->to_date,
            'user' => [
                'id' => $user->id,
                'image' => $telegram->image ?? null,
            ]
        ];
    }
}
