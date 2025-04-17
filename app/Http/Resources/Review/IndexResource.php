<?php

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IndexResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $user = $this->owner;
        $telegram = $user->telegramUser;

        return [
            'id' => $this->id,
            'text' => $this->text,
            'rating' => $this->rating,
            'created_at' => $this->created_at,
            'owner' => [
                'id' => $user->id,
                'name' => $user->name,
                'image' => $telegram->image ?? null,
            ]
        ];
    }
}
