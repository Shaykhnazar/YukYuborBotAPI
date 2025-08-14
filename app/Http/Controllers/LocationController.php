<?php

namespace App\Http\Controllers;

use App\Models\SuggestedRoute;
use App\Services\LocationCacheService;
use App\Services\RouteCacheService;
use App\Services\TelegramUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    public function __construct(
        protected TelegramUserService $tgService,
        protected LocationCacheService $locationCacheService,
        protected RouteCacheService $routeCacheService,
    ) {}

    public function getCountries()
    {
        $countries = $this->locationCacheService->getCountriesWithPopularCities(3);
        return response()->json($countries);
    }

    public function getCitiesByCountry(Request $request, $countryId)
    {
        $query = $request->get('q', ''); // Get search query parameter
        $cities = $this->locationCacheService->getCitiesByCountry($countryId, $query ?: null);
        return response()->json($cities);
    }

    public function searchLocations(Request $request)
    {
        $query = $request->get('q', '');
        $type = $request->get('type', 'all'); // 'country', 'city', or 'all'

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $locations = $this->locationCacheService->searchLocations($query, $type, 10);
        return response()->json($locations);
    }

    public function popularRoutes()
    {
        try {
            // Get popular routes from cache
            $routes = $this->routeCacheService->getPopularRoutes();
            return response()->json($routes);

        } catch (\Exception $e) {
            Log::error('Error fetching popular routes', ['error' => $e->getMessage()]);
            return response()->json([]);
        }
    }


    public function suggestRoute(Request $request)
    {
        $request->validate([
            'from_location' => 'required|string|max:255',
            'to_location' => 'required|string|max:255|different:from_location',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = $this->tgService->getUserByTelegramId($request);

        $suggestion = SuggestedRoute::create([
            'from_location' => $request->from_location,
            'to_location' => $request->to_location,
            'user_id' => $user->id,
            'status' => 'pending',
            'notes' => $request->notes ?? null,
        ]);

        return response()->json([
            'message' => 'Маршрут отправлен на модерацию',
            'suggestion' => $suggestion
        ]);
    }

}
