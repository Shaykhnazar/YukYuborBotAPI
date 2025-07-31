<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Route;
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
            ->get(['id', 'name', 'country_code', 'is_active']);

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
            // Get approved routes with location details and request counts
            $routes = Route::active()
                ->with(['fromLocation.parent', 'toLocation.parent'])
                ->byPriority()
                ->get()
                ->map(function ($route) {
                    // Get from location details
                    $fromLocation = $route->fromLocation;
                    $fromCountry = $fromLocation->type === 'country'
                        ? $fromLocation
                        : $fromLocation->parent;

                    // Get to location details
                    $toLocation = $route->toLocation;
                    $toCountry = $toLocation->type === 'country'
                        ? $toLocation
                        : $toLocation->parent;

                    // Get popular cities for both countries
                    $fromCities = Location::cities()
                        ->where('parent_id', $fromCountry->id)
                        ->active()
                        ->orderBy('name')
                        ->limit(3)
                        ->get(['id', 'name']);

                    $toCities = Location::cities()
                        ->where('parent_id', $toCountry->id)
                        ->active()
                        ->orderBy('name')
                        ->limit(3)
                        ->get(['id', 'name']);

                    // Count active requests for this route
                    $activeRequestsCount = $this->getActiveRequestsForRoute($fromCountry->name, $toCountry->name);

                    return [
                        'id' => $route->id,
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
                        'active_requests' => $activeRequestsCount,
                        'popular_cities' => [
                            ...$fromCities->map(fn($city) => [
                                'id' => $city->id,
                                'name' => $city->name
                            ]),
                            ...$toCities->map(fn($city) => [
                                'id' => $city->id,
                                'name' => $city->name
                            ])
                        ],
                        'priority' => $route->priority,
                        'description' => $route->description
                    ];
                });

            return response()->json($routes);

        } catch (\Exception $e) {
            Log::error('Error fetching popular routes', ['error' => $e->getMessage()]);
            return response()->json([]);
        }
    }

    private function getActiveRequestsForRoute($fromCountryName, $toCountryName): int
    {
        // Count requests matching this route (using existing string fields for now)
        $deliveryCount = DB::table('delivery_requests')
            ->where('status', 'IN', ['open', 'has_responses'])
            ->where('from_location', 'ILIKE', '%' . $fromCountryName . '%')
            ->where('to_location', 'ILIKE', '%' . $toCountryName . '%')
            ->count();

        $sendCount = DB::table('send_requests')
            ->where('status', 'IN', ['open', 'has_responses'])
            ->where('from_location', 'ILIKE', '%' . $fromCountryName . '%')
            ->where('to_location', 'ILIKE', '%' . $toCountryName . '%')
            ->count();

        return $deliveryCount + $sendCount;
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
