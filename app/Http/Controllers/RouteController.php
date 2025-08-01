<?php

namespace App\Http\Controllers;

use App\Models\Route;
use App\Http\Resources\RouteResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouteController extends Controller
{
    public function index(Request $request)
    {
        // Build base query
        $query = Route::query()
            ->with(['fromLocation', 'toLocation'])
//            ->when($request->boolean('active'), fn ($q) => $q->active()) // TODO: Inactive routes also should appear on routes page
            ->when(
                $request->filled('order_by') && $request->order_by === 'priority',
                fn ($q) => $q->byPriority()
            );

        // Get routes with counts using the model method
        $routes = Route::withActiveRequestsCounts($query);

        return RouteResource::collection($routes);
    }
}
