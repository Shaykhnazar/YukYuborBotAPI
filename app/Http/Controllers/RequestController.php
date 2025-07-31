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
            ->with(['user.telegramUser', 'fromLocation', 'toLocation'])
            ->whereIn('status', ['open', 'has_responses'])
            ->where('user_id', '!=', $currentUser->id);

        $send = SendRequest::query()
            ->selectRaw("send_requests.*, 'send' as type")
            ->with(['user.telegramUser', 'fromLocation', 'toLocation'])
            ->whereIn('status', ['open', 'has_responses'])
            ->where('user_id', '!=', $currentUser->id);

        /* -----------------  Route filter (country-aware)  ----------------- */
        if (!empty($filters['from_location_id']) && !empty($filters['to_location_id'])) {
            $fromId = $filters['from_location_id'];
            $toId   = $filters['to_location_id'];

            foreach ([$delivery, $send] as $q) {
                // origin
                $q->where(function ($q) use ($fromId) {
                    $q->whereHas('fromLocation', fn ($l) => $l->where('parent_id', $fromId))
                        ->orWhere('from_location_id', $fromId);
                });

                // destination
                $q->where(function ($q) use ($toId) {
                    $q->whereHas('toLocation', fn ($l) => $l->where('parent_id', $toId))
                        ->orWhere('to_location_id', $toId);
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

}
