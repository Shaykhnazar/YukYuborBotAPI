<?php

namespace App\Http\Controllers;

use App\Http\Requests\Parcel\ParcelRequest;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Service\TelegramUserService;

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

        /* -----------------  Pagination  ----------------- */
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = min(50, max(5, (int) $request->input('per_page', 10)));

        /* -----------------  Filters  ----------------- */
        $filters = $request->getFilters();

        /* -----------------  Base queries  ----------------- */
        $delivery = DeliveryRequest::query()
            ->selectRaw("delivery_requests.*, 'delivery' as type")
            ->whereIn('status', ['open', 'has_responses'])
            /*->where('user_id', '!=', $currentUser->id)*/; //  now user can see own requests on requests page

        $send = SendRequest::query()
            ->selectRaw("send_requests.*, 'send' as type")
            ->whereIn('status', ['open', 'has_responses'])
            /*->where('user_id', '!=', $currentUser->id)*/; //  now user can see own requests on requests page

        /* -----------------  Route filter (country-aware with reverse direction)  ----------------- */
        if (!empty($filters['from_location_id']) && !empty($filters['to_location_id'])) {
            $fromId = $filters['from_location_id'];
            $toId   = $filters['to_location_id'];

            foreach ([$delivery, $send] as $q) {
                $q->where(function ($mainQuery) use ($fromId, $toId) {
                    // Forward direction: from_location matches fromId AND to_location matches toId
                    $mainQuery->where(function ($forwardQuery) use ($fromId, $toId) {
                        $forwardQuery->where(function ($fromQuery) use ($fromId) {
                            $fromQuery->whereHas('fromLocation', fn ($l) => $l->where('parent_id', $fromId))
                                     ->orWhere('from_location_id', $fromId);
                        })->where(function ($toQuery) use ($toId) {
                            $toQuery->whereHas('toLocation', fn ($l) => $l->where('parent_id', $toId))
                                   ->orWhere('to_location_id', $toId);
                        });
                    })
                    // Reverse direction: from_location matches toId AND to_location matches fromId
                    ->orWhere(function ($reverseQuery) use ($fromId, $toId) {
                        $reverseQuery->where(function ($fromQuery) use ($toId) {
                            $fromQuery->whereHas('fromLocation', fn ($l) => $l->where('parent_id', $toId))
                                     ->orWhere('from_location_id', $toId);
                        })->where(function ($toQuery) use ($fromId) {
                            $toQuery->whereHas('toLocation', fn ($l) => $l->where('parent_id', $fromId))
                                   ->orWhere('to_location_id', $fromId);
                        });
                    });
                });
            }
        }

        /* -----------------  Search filter  ----------------- */
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';

            $delivery->where(function ($q) use ($searchTerm) {
                $q->whereHas('fromLocation', fn($b) => $b->where('name', 'ILIKE', $searchTerm))
                  ->orWhereHas('toLocation',   fn($b) => $b->where('name', 'ILIKE', $searchTerm))
                  ->orWhere('description', 'ILIKE', $searchTerm);
            });

            $send->where(function ($q) use ($searchTerm) {
                $q->whereHas('fromLocation', fn($b) => $b->where('name', 'ILIKE', $searchTerm))
                  ->orWhereHas('toLocation',   fn($b) => $b->where('name', 'ILIKE', $searchTerm))
                  ->orWhere('description', 'ILIKE', $searchTerm);
            });
        }

        /* -----------------  Decide which table(s) to use  ----------------- */
        $union = match ($filters['filter'] ?? null) {
            'delivery' => $delivery,
            'send' => $send,
            default => $delivery->unionAll($send),
        };

        /* -----------------  Execute with pagination  ----------------- */
        $results = $union->orderByDesc('created_at')
                         ->paginate($perPage, ['*'], 'page', $page);

        // Load relationships after pagination for better performance
        $this->loadRelationshipsAfterPagination($results);

        /* -----------------  Response  ----------------- */
        return response()->json([
            'data' => IndexRequestResource::collection($results),
            'pagination' => [
                'current_page' => $results->currentPage(),
                'last_page'    => $results->lastPage(),
                'total'        => $results->total(),
                'per_page'     => $results->perPage(),
                'has_more'     => $results->hasMorePages(),
            ]
        ]);
    }

    /**
     * Load relationships after pagination to avoid UNION query conflicts
     */
    private function loadRelationshipsAfterPagination($results)
    {
        $deliveryIds = [];
        $sendIds = [];

        // Separate IDs by type
        foreach ($results->items() as $item) {
            if ($item->type === 'delivery') {
                $deliveryIds[] = $item->id;
            } else {
                $sendIds[] = $item->id;
            }
        }

        // Load delivery requests with relationships
        $deliveryRequests = DeliveryRequest::whereIn('id', $deliveryIds)
            ->with(['user.telegramUser', 'fromLocation', 'toLocation'])
            ->get()
            ->keyBy('id');

        // Load send requests with relationships
        $sendRequests = SendRequest::whereIn('id', $sendIds)
            ->with(['user.telegramUser', 'fromLocation', 'toLocation'])
            ->get()
            ->keyBy('id');

        // Merge the loaded relationships back into the paginated results
        foreach ($results->items() as $item) {
            if ($item->type === 'delivery' && isset($deliveryRequests[$item->id])) {
                $loadedRequest = $deliveryRequests[$item->id];
                $item->setRelations($loadedRequest->getRelations());
            } elseif ($item->type === 'send' && isset($sendRequests[$item->id])) {
                $loadedRequest = $sendRequests[$item->id];
                $item->setRelations($loadedRequest->getRelations());
            }
        }
    }

}
