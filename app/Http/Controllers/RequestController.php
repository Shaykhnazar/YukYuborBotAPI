<?php

namespace App\Http\Controllers;

use App\Http\Requests\Parcel\ParcelRequest;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Service\TelegramUserService;
use Illuminate\Support\Facades\DB;
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
        $offset = ($page - 1) * $perPage;

        Log::info('Public requests filters', $filters);

        // Build the UNION query for combining both tables efficiently
        $deliverySelect = DB::table('delivery_requests')
            ->select(
                'id',
                'from_location',
                'to_location',
                'user_id',
                'from_date',
                'to_date',
                'size_type',
                'description',
                'status',
                'created_at',
                'updated_at',
                'price',
                'currency',
                DB::raw("'delivery' as type")
            )
            ->whereIn('status', ['open', 'has_responses'])
            ->where('user_id', '!=', $currentUser->id);

        $sendSelect = DB::table('send_requests')
            ->select(
                'id',
                'from_location',
                'to_location',
                'user_id',
                'from_date',
                'to_date',
                'size_type',
                'description',
                'status',
                'created_at',
                'updated_at',
                'price',
                'currency',
                DB::raw("'send' as type")
            )
            ->whereIn('status', ['open', 'has_responses'])
            ->where('user_id', '!=', $currentUser->id);

        // Apply search filters if provided
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';

            $deliverySelect->where(function($query) use ($searchTerm) {
                $query->where('from_location', 'ILIKE', $searchTerm)
                    ->orWhere('to_location', 'ILIKE', $searchTerm)
                    ->orWhere('description', 'ILIKE', $searchTerm);
            });

            $sendSelect->where(function($query) use ($searchTerm) {
                $query->where('from_location', 'ILIKE', $searchTerm)
                    ->orWhere('to_location', 'ILIKE', $searchTerm)
                    ->orWhere('description', 'ILIKE', $searchTerm);
            });
        }

        // Build final query based on filter type
        if ($filters['filter'] === 'send') {
            $baseQuery = $sendSelect;
        } elseif ($filters['filter'] === 'delivery') {
            $baseQuery = $deliverySelect;
        } else {
            // Combine both tables using UNION ALL for better performance
            $baseQuery = $deliverySelect->unionAll($sendSelect);
        }

        // Get total count for pagination metadata
        $totalCount = DB::table(DB::raw("({$baseQuery->toSql()}) as combined_requests"))
            ->mergeBindings($baseQuery)
            ->count();

        // Apply ordering and pagination at database level
        $paginatedResults = DB::table(DB::raw("({$baseQuery->toSql()}) as combined_requests"))
            ->mergeBindings($baseQuery)
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        // Efficiently load models with relationships in batches
        $requests = $this->hydrateModelsWithRelationships($paginatedResults);

        // Calculate pagination metadata
        $lastPage = ceil($totalCount / $perPage);

        Log::info('Public requests result count', [
            'total_count' => $totalCount,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'returned_count' => $requests->count(),
            'offset' => $offset
        ]);

        return response()->json([
            'data' => IndexRequestResource::collection($requests),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $totalCount,
                'per_page' => $perPage,
                'has_more' => $page < $lastPage
            ]
        ]);
    }

    /**
     * Efficiently hydrate models with relationships to avoid N+1 queries
     */
    private function hydrateModelsWithRelationships($results)
    {
        // Group results by type for batch loading
        $deliveryIds = [];
        $sendIds = [];

        foreach ($results as $result) {
            if ($result->type === 'delivery') {
                $deliveryIds[] = $result->id;
            } else {
                $sendIds[] = $result->id;
            }
        }

        // Batch load models with relationships
        $deliveryModels = collect();
        $sendModels = collect();

        if (!empty($deliveryIds)) {
            $deliveryModels = DeliveryRequest::with('user.telegramUser')
                ->whereIn('id', $deliveryIds)
                ->get()
                ->keyBy('id');
        }

        if (!empty($sendIds)) {
            $sendModels = SendRequest::with('user.telegramUser')
                ->whereIn('id', $sendIds)
                ->get()
                ->keyBy('id');
        }

        // Rebuild the collection maintaining the original order
        $requests = collect();
        foreach ($results as $result) {
            if ($result->type === 'delivery' && $deliveryModels->has($result->id)) {
                $model = $deliveryModels->get($result->id);
                $model->type = 'delivery';
                $requests->push($model);
            } elseif ($result->type === 'send' && $sendModels->has($result->id)) {
                $model = $sendModels->get($result->id);
                $model->type = 'send';
                $requests->push($model);
            }
        }

        return $requests;
    }

    /**
     * Apply search filter to query builder (kept for compatibility)
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
