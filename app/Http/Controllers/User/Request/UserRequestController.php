<?php

namespace App\Http\Controllers\User\Request;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Parcel\ParcelRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Service\TelegramUserService;
use App\Models\User;
use App\Models\Response;

class UserRequestController extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    )
    {}

    public function index(ParcelRequest $request)
    {
        $filter = $request->getFilter();
        $user = $this->tgService->getUserByTelegramId($request);

        // Load both relationships to avoid N+1 queries in resource
        $user->load(['sendRequests', 'deliveryRequests']);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->map(function ($item) {
                $item->type = 'send';
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->map(function ($item) {
                $item->type = 'delivery';
                return $item;
            });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        // Optimize: Get all responses for these requests in a single query
        $this->addResponsesFlags($requests);

        return IndexRequestResource::collection($requests);
    }

    public function show(ParcelRequest $request, int $id)
    {
        $filter = $request->getFilter();
        $user = $this->tgService->getUserByTelegramId($request);

        // Load both relationships to avoid N+1 queries in resource
        $user->load(['sendRequests', 'deliveryRequests']);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->where('id', $id)->map(function ($item) {
                $item->type = 'send';
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->where('id', $id)->map(function ($item) {
                $item->type = 'delivery';
                return $item;
            });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        // Add responses flags for single request
        $this->addResponsesFlags($requests);

        return IndexRequestResource::collection($requests);
    }

    public function userRequests(ParcelRequest $request, User $user)
    {
        $filter = $request->getFilter();

        // Load both relationships to avoid N+1 queries in resource
        $user->load(['sendRequests', 'deliveryRequests']);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->map(function ($item) {
                $item->type = 'send';
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->map(function ($item) {
                $item->type = 'delivery';
                return $item;
            });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        // Add responses flags
        $this->addResponsesFlags($requests);

        return IndexRequestResource::collection($requests);
    }

    /**
     * Add has_responses flag to requests collection in a single optimized query
     */
    private function addResponsesFlags($requests): void
    {
        if ($requests->isEmpty()) {
            return;
        }

        // Group request IDs by type for efficient querying
        $sendRequestIds = $requests->where('type', 'send')->pluck('id')->toArray();
        $deliveryRequestIds = $requests->where('type', 'delivery')->pluck('id')->toArray();

        $responsesLookup = [];

        // Single query to get all responses for all requests
        if (!empty($sendRequestIds) || !empty($deliveryRequestIds)) {
            $responsesQuery = Response::where('status', '!=', 'rejected');

            $responsesQuery->where(function($query) use ($sendRequestIds, $deliveryRequestIds) {
                if (!empty($sendRequestIds)) {
                    $query->orWhere(function($q) use ($sendRequestIds) {
                        $q->where('request_type', 'send')
                          ->whereIn('request_id', $sendRequestIds);
                    });
                }
                if (!empty($deliveryRequestIds)) {
                    $query->orWhere(function($q) use ($deliveryRequestIds) {
                        $q->where('request_type', 'delivery')
                          ->whereIn('request_id', $deliveryRequestIds);
                    });
                }
            });

            $responses = $responsesQuery->select('request_type', 'request_id')
                                      ->distinct()
                                      ->get();

            // Create efficient lookup map: type => [request_id => true]
            foreach ($responses as $response) {
                $responsesLookup[$response->request_type][$response->request_id] = true;
            }
        }

        // Add has_responses flag to each request
        $requests->transform(function ($item) use ($responsesLookup) {
            $item->has_responses = isset($responsesLookup[$item->type][$item->id]);
            return $item;
        });
    }
}
