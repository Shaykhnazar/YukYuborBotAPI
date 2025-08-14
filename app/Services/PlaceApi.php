<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PlaceApi
{
    protected string $url = 'https://nominatim.openstreetmap.org/';

    public function search_by_city(string $city): array
    {
        $data = [
            'city' => $city,
            'accept-language' => 'ru',
            'limit' => '5',
            'format' => 'jsonv2'
        ];
        $response = Http::withOptions([
            'verify' => false,
        ])
            ->withHeaders([
                'User-Agent' => 'PostLinkApi/1.0 (miko20657@gmail.com)',
                'Accept' => 'application/json',
            ])
            ->get($this->url.'search', $data);

        $places = $response->json();
        $names = array_column($places, 'display_name');
        return $names;
    }
}
