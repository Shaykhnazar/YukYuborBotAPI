<?php

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $owner = $this->owner;
        $telegram = $owner ? $owner->telegramUser : null;

        return [
            'id' => $this->id,
            'text' => $this->text,
            'rating' => $this->rating,
            'created_at' => $this->created_at,
            'owner' => [
                'id' => $owner ? $owner->id : null,
                'name' => $owner ? $owner->name : null,
                'image' => $telegram ? $telegram->image : null,
            ]
        ];
    }
}
