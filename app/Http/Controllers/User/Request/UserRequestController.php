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
        $this->addResponsesAndChatInfo($requests);

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
        $this->addResponsesAndChatInfo($requests);

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
        $this->addResponsesAndChatInfo($requests);

        return IndexRequestResource::collection($requests);
    }

    /**
     * Add has_responses flag and chat_id to requests collection in a single optimized query
     */
    private function addResponsesAndChatInfo($requests): void
    {
        if ($requests->isEmpty()) {
            return;
        }

        // Group request IDs by type for efficient querying
        $sendRequestIds = $requests->where('type', 'send')->pluck('id')->toArray();
        $deliveryRequestIds = $requests->where('type', 'delivery')->pluck('id')->toArray();

        $responsesLookup = [];
        $chatLookup = [];

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

            $responses = $responsesQuery->select('request_type', 'request_id', 'chat_id', 'status')
                                      ->get();

            // Create efficient lookup maps
            foreach ($responses as $response) {
                // Mark that this request has responses
                $responsesLookup[$response->request_type][$response->request_id] = true;

                // If response is accepted and has chat_id, store it
                if ($response->status === 'accepted' && $response->chat_id) {
                    $chatLookup[$response->request_type][$response->request_id] = $response->chat_id;
                }
            }
        }

        // Add has_responses flag and chat_id to each request
        $requests->transform(function ($item) use ($responsesLookup, $chatLookup) {
            $item->has_responses = isset($responsesLookup[$item->type][$item->id]);
            $item->chat_id = $chatLookup[$item->type][$item->id] ?? null;
            return $item;
        });
    }
}
