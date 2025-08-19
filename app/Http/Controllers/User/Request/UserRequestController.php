<?php

namespace App\Http\Controllers\User\Request;

use App\Http\Controllers\Controller;
use App\Http\Requests\Parcel\ParcelRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Models\User;
use App\Services\TelegramUserService;
use App\Services\UserRequest\UserRequestService;
use Illuminate\Support\Facades\Log;

class UserRequestController extends Controller
{
    public function __construct(
        protected TelegramUserService $tgService,
        protected UserRequestService $userRequestService
    ) {}

    /**
     * Get current user's requests with responses
     */
    public function index(ParcelRequest $request)
    {
        try {
            $user = $this->tgService->getUserByTelegramId($request);
            $filters = $request->getFilters();

            // Get raw requests without formatting since IndexRequestResource will handle formatting
            $requests = $this->userRequestService->getUserRequestsRaw($user, $filters);

            return IndexRequestResource::collection($requests);

        } catch (\Exception $e) {
            Log::error('Error fetching user requests', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);

            return response()->json(['error' => 'Failed to fetch user requests'], 500);
        }
    }

    /**
     * Get specific user request by ID
     */
    public function show(ParcelRequest $request, int $id)
    {
        try {
            $user = $this->tgService->getUserByTelegramId($request);
            $filters = $request->getFilters();

            $requests = $this->userRequestService->getUserRequestRaw($user, $id, $filters);

            return IndexRequestResource::collection($requests);

        } catch (\Exception $e) {
            Log::error('Error fetching user request', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
                'request_id' => $id
            ]);

            return response()->json(['error' => 'Failed to fetch request'], 500);
        }
    }

    /**
     * Get another user's requests
     */
    public function userRequests(ParcelRequest $request, User $targetUser)
    {
        try {
            $currentUser = $this->tgService->getUserByTelegramId($request);
            $filters = $request->getFilters();

            $requests = $this->userRequestService->getOtherUserRequestsRaw($targetUser, $currentUser, $filters);

            return IndexRequestResource::collection($requests);

        } catch (\Exception $e) {
            Log::error('Error fetching other user requests', [
                'error' => $e->getMessage(),
                'current_user_id' => $currentUser->id ?? null,
                'target_user_id' => $targetUser->id
            ]);

            return response()->json(['error' => 'Failed to fetch user requests'], 500);
        }
    }

    /**
     * Get user request statistics
     */
    public function stats(ParcelRequest $request)
    {
        try {
            $user = $this->tgService->getUserByTelegramId($request);

            $stats = $this->userRequestService->getUserRequestStats($user);

            return response()->json($stats);

        } catch (\Exception $e) {
            Log::error('Error fetching user request stats', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);

            return response()->json(['error' => 'Failed to fetch user stats'], 500);
        }
    }
}