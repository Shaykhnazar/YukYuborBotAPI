<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from' => [
                'id' => $this->fromLocation->id,
                'name' => $this->fromLocation->name,
            ],
            'to' => [
                'id' => $this->toLocation->id,
                'name' => $this->toLocation->name,
            ],
            'from_id' => $this->from_location_id,
            'to_id' => $this->to_location_id,
            'from_location_id' => $this->from_location_id,
            'to_location_id' => $this->to_location_id,
            'is_active' => $this->is_active,
            'priority' => $this->priority,
            'description' => $this->description,
            'active_requests_count' => $this->active_requests_count ?? 0,
            'popular_cities' => $this->getPopularCities(),
        ];
    }

    private function getPopularCities()
    {
        // Get popular cities from both from and to locations
        $fromCities = $this->fromLocation->children->take(2);
        $toCities = $this->toLocation->children->take(2);

        return $fromCities->concat($toCities)->map(function($city) {
            return [
                'id' => $city->id,
                'name' => $city->name,
            ];
        })->take(4)->values();
    }
}
