<?php

namespace App\Http\Controllers;

use App\Models\Route;
use App\Http\Resources\RouteResource;
use App\Services\RouteCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouteController extends Controller
{
    public function __construct(
        private RouteCacheService $routeCacheService
    ) {}

    public function index(Request $request)
    {
        // For simple active routes with counts, use cache
        if ($request->boolean('active') && (!$request->filled('order_by') || $request->order_by === 'priority')) {
            $routes = $this->routeCacheService->getRoutesWithRequestCounts();
            return RouteResource::collection($routes);
        }

        // For complex filtering, fall back to database query
        $query = Route::query()
            ->with(['fromLocation', 'toLocation'])
            ->when($request->boolean('active'), fn ($q) => $q->active())
            ->when(
                $request->filled('order_by') && $request->order_by === 'priority',
                fn ($q) => $q->byPriority()
            );

        // Get routes with counts using the model method
        $routes = Route::withActiveRequestsCounts($query);

        return RouteResource::collection($routes);
    }
}
