<?php
// tests/Unit/Services/PlaceApiTest.php

use App\Service\PlaceApi;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->placeApi = new PlaceApi();
});

it('searches cities by name', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            ['display_name' => 'Tashkent, Uzbekistan'],
            ['display_name' => 'Tashkent Region, Uzbekistan']
        ], 200)
    ]);

    $result = $this->placeApi->search_by_city('Tashkent');

    expect($result)->toHaveCount(2);
    expect($result[0])->toBe('Tashkent, Uzbekistan');
});
