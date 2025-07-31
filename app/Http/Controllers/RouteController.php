<?php

namespace App\Http\Controllers;

use App\Models\Route;
use App\Http\Resources\RouteResource;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    public function index(Request $request)
    {
        $routes = Route::query()
            ->with(['fromLocation', 'toLocation', 'fromLocation.children', 'toLocation.children'])
            ->when($request->boolean('active'), function($query) {
                $query->active();
            })
            ->when($request->filled('order_by') && $request->order_by === 'priority', function($query) {
                $query->byPriority();
            })
            ->get();

        return RouteResource::collection($routes);
    }
}
