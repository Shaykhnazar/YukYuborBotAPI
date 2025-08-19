<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Http\Requests\Send\CreateSendRequest;
use App\Jobs\MatchRequestsJob;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use App\Services\RequestService;
use App\Services\TelegramUserService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SendRequestController extends Controller
{
    public function __construct(
        protected TelegramUserService $userService,
        protected RequestService $requestService,
        protected SendRequestRepositoryInterface $sendRequestRepository
    ) {}

    public function create(CreateSendRequest $request)
    {
        try {
            $user = $this->userService->getUserByTelegramId($request);
            
            $this->requestService->checkActiveRequestsLimit($user);

            $dto = $request->getDTO();
            
            $sendRequest = $this->sendRequestRepository->create([
                'from_location_id' => $dto->fromLocId,
                'to_location_id' => $dto->toLocId,
                'description' => $dto->desc ?? null,
                'from_date' => CarbonImmutable::now()->toDateString(),
                'to_date' => $dto->toDate->toDateString(),
                'price' => $dto->price ?? null,
                'currency' => $dto->currency ?? null,
                'user_id' => $user->id,
                'status' => RequestStatus::OPEN->value,
            ]);

            // Dispatch matching job in background
            MatchRequestsJob::dispatch('send', $sendRequest->id);

            return response()->json($sendRequest);

        } catch (\Exception $e) {
            Log::error('Error creating send request', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'errorTitle' => 'Ошибка создания заявки'
            ], 422);
        }
    }

    public function delete(Request $request, int $id)
    {
        try {
            $user = $this->userService->getUserByTelegramId($request);

            $sendRequest = $this->sendRequestRepository->findByUserAndId($user, $id);

            if (!$sendRequest) {
                return response()->json(['error' => 'Request not found'], 404);
            }

            $this->requestService->deleteRequest($sendRequest);

            return response()->json(['message' => 'Request deleted successfully']);

        } catch (\Exception $e) {
            Log::error('Error deleting send request', [
                'request_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function close(Request $request, int $id)
    {
        try {
            $user = $this->userService->getUserByTelegramId($request);

            $sendRequest = $this->sendRequestRepository->findByUserAndId($user, $id);

            if (!$sendRequest) {
                return response()->json(['error' => 'Send request not found'], 404);
            }

            $this->requestService->closeRequest($sendRequest);

            return response()->json(['message' => 'Send request closed successfully']);

        } catch (\Exception $e) {
            Log::error('Error closing send request', [
                'request_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $user = $this->userService->getUserByTelegramId($request);
            $sendRequests = $this->sendRequestRepository->findActiveByUser($user);

            return response()->json($sendRequests);

        } catch (\Exception $e) {
            Log::error('Error fetching user send requests', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);

            return response()->json(['error' => 'Failed to fetch requests'], 500);
        }
    }
}