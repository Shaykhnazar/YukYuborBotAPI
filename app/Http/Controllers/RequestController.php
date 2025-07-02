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

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        Log::info('Public requests result count', [
            'count' => $requests->count(),
            'delivery_count' => $delivery->count(),
            'send_count' => $send->count()
        ]);

        return IndexRequestResource::collection($requests);
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
