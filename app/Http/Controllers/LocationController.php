<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\SuggestedRoute;
use App\Service\TelegramUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    public function __construct(
        protected TelegramUserService $tgService,
    ) {}

    public function getCountries()
    {
        $countries = Location::countries()
            ->active()
            ->with(['children' => function($query) {
                $query->select('id', 'name', 'parent_id')
                    ->active()
                    ->orderBy('name')
                    ->limit(3); // Get only first 3 cities as popular
            }])
            ->orderBy('name')
            ->get(['id', 'name', 'country_code']);

        return response()->json($countries);
    }

    public function getCitiesByCountry(Request $request, $countryId)
    {
        $query = $request->get('q', ''); // Get search query parameter

        $citiesQuery = Location::with('parent:id,name')
            ->cities()
            ->where('parent_id', $countryId)
            ->active()
            ->orderBy('name');

        // Apply search filter if query is provided
        if (!empty($query)) {
            $citiesQuery->where('name', 'ILIKE', '%' . $query . '%');
        }

        $cities = $citiesQuery->get(['id', 'name', 'parent_id']);

        return response()->json($cities);
    }

    public function searchLocations(Request $request)
    {
        $query = $request->get('q', '');
        $type = $request->get('type', 'all'); // 'country', 'city', or 'all'

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $locations = Location::active()
            ->where('name', 'ILIKE', '%' . $query . '%')
            ->when($type !== 'all', function ($q) use ($type) {
                return $q->where('type', $type);
            })
            ->with('parent')
            ->orderBy('name')
            ->limit(10)
            ->get()
            ->map(function ($location) {
                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'type' => $location->type,
                    'display_name' => $location->type === 'city'
                        ? $location->parent->name . ', ' . $location->name
                        : $location->name,
                    'parent_id' => $location->parent_id,
                    'country_name' => $location->type === 'city'
                        ? $location->parent->name
                        : $location->name,
                ];
            });

        return response()->json($locations);
    }

    public function popularRoutes()
    {
        try {
            // Get popular routes with actual request counts from real requests
            $routes = DB::select("
            SELECT
                from_location,
                to_location,
                COUNT(*) as active_requests
            FROM (
                SELECT from_location, to_location FROM delivery_requests WHERE status IN ('open', 'has_responses')
                UNION ALL
                SELECT from_location, to_location FROM send_requests WHERE status IN ('open', 'has_responses')
            ) as all_requests
            GROUP BY from_location, to_location
           /* HAVING COUNT(*) >= 1*/
            ORDER BY COUNT(*) DESC
            LIMIT 10
        ");

            // If no actual routes exist, return empty array
            if (empty($routes)) {
                return response()->json([]);
            }

            // Process existing routes and add popular cities
            $popularRoutes = collect($routes)->map(function ($route) {
                // Extract country names from location strings (assuming format like "City, Country")
                $fromParts = explode(',', $route->from_location);
                $toParts = explode(',', $route->to_location);

                $fromCountryName = trim(end($fromParts));
                $toCountryName = trim(end($toParts));

                // Find countries by name
                $fromCountry = Location::countries()->where('name', 'ILIKE', '%' . $fromCountryName . '%')->first();
                $toCountry = Location::countries()->where('name', 'ILIKE', '%' . $toCountryName . '%')->first();

                if ($fromCountry && $toCountry) {
                    // Get popular cities for both countries
                    $fromCities = Location::cities()
                        ->where('parent_id', $fromCountry->id)
                        ->active()
                        ->orderBy('name')
                        ->limit(2)
                        ->get(['id', 'name']);

                    $toCities = Location::cities()
                        ->where('parent_id', $toCountry->id)
                        ->active()
                        ->orderBy('name')
                        ->limit(2)
                        ->get(['id', 'name']);

                    return [
                        'id' => base64_encode($route->from_location . '-' . $route->to_location),
                        'from' => [
                            'id' => $fromCountry->id,
                            'name' => $fromCountry->name,
                            'type' => 'country'
                        ],
                        'to' => [
                            'id' => $toCountry->id,
                            'name' => $toCountry->name,
                            'type' => 'country'
                        ],
                        'active_requests' => (int) $route->active_requests,
                        'popular_cities' => [
                            ...$fromCities->map(fn($city) => ['id' => $city->id, 'name' => $city->name]),
                            ...$toCities->map(fn($city) => ['id' => $city->id, 'name' => $city->name])
                        ]
                    ];
                }

                return null;
            })->filter()->values();

            return response()->json($popularRoutes);

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
