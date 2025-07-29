<?php

namespace App\Http\Controllers;

use App\Http\Requests\Parcel\ParcelRequest;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Service\TelegramUserService;
use Illuminate\Support\Facades\Log;

class RequestController extends Controller
{
    public function __construct(
        protected TelegramUserService $userService
    ) {}

    public function index(ParcelRequest $request)
    {
        // Get current user to exclude their requests
        $currentUser = $this->userService->getUserByTelegramId($request);

        if (!$currentUser) {
            return response()->json(['error' => 'User not found'], 401);
        }

        $filters = $request->getFilters();
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 10);
        
        // Validate pagination parameters
        $page = max(1, $page);
        $perPage = min(50, max(5, $perPage)); // Limit between 5-50 items per page
        
        $delivery = collect();
        $send = collect();

        Log::info('Public requests filters', $filters);

        // Apply request type filter
        if ($filters['filter'] !== 'send') {
            $deliveryQuery = DeliveryRequest::with('user.telegramUser')
                ->whereIn('status', ['open', 'has_responses']);

            // Apply search filter to delivery requests
            if ($filters['search']) {
                $deliveryQuery = $this->applySearchFilter($deliveryQuery, $filters['search']);
            }

            $delivery = $deliveryQuery->get()->map(function ($item) {
                $item->type = 'delivery';
                return $item;
            });
        }

        if ($filters['filter'] !== 'delivery') {
            $sendQuery = SendRequest::with('user.telegramUser')
                ->whereIn('status', ['open', 'has_responses']);

            // Apply search filter to send requests
            if ($filters['search']) {
                $sendQuery = $this->applySearchFilter($sendQuery, $filters['search']);
            }

            $send = $sendQuery->get()->map(function ($item) {
                $item->type = 'send';
                return $item;
            });
        }

        // Combine and sort all requests
        $allRequests = $delivery->concat($send)->sortByDesc('created_at')->values();
        $totalCount = $allRequests->count();
        
        // Calculate pagination
        $lastPage = ceil($totalCount / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedRequests = $allRequests->slice($offset, $perPage)->values();

        Log::info('Public requests result count', [
            'total_count' => $totalCount,
            'delivery_count' => $delivery->count(),
            'send_count' => $send->count(),
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage
        ]);

        return response()->json([
            'data' => IndexRequestResource::collection($paginatedRequests),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $totalCount,
                'per_page' => $perPage
            ]
        ]);
    }

    /**
     * Apply search filter to query builder
     */
    private function applySearchFilter($query, string $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('from_location', 'ILIKE', "%{$search}%")
              ->orWhere('to_location', 'ILIKE', "%{$search}%")
              ->orWhere('description', 'ILIKE', "%{$search}%")
              ->orWhereHas('user', function($userQuery) use ($search) {
                  $userQuery->where('name', 'ILIKE', "%{$search}%");
              });
        });
    }
}
