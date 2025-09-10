<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Http\Requests\Delivery\CreateDeliveryRequest;
use App\Jobs\MatchRequestsJob;
use App\Models\DeliveryRequest;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Services\RequestService;
use App\Services\TelegramUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeliveryController extends Controller
{
    public function __construct(
        protected TelegramUserService $userService,
        protected RequestService $requestService,
        protected DeliveryRequestRepositoryInterface $deliveryRequestRepository
    ) {}

    public function create(CreateDeliveryRequest $request)
    {
        try {
            $user = $this->userService->getUserByTelegramId($request);

            $this->requestService->checkActiveRequestsLimit($user);

            $dto = $request->getDTO();
            
            // Check for duplicate route and date
            $this->requestService->checkDuplicateRoute(
                $user, 
                $dto->fromLocId, 
                $dto->toLocId, 
                $dto->toDate->toDateString(), 
                'delivery'
            );

            $deliveryRequest = $this->deliveryRequestRepository->create([
                'from_location_id' => $dto->fromLocId,
                'to_location_id' => $dto->toLocId,
                'description' => $dto->desc ?? null,
                'from_date' => $dto->fromDate->toDateString(),
                'to_date' => $dto->toDate->toDateString(),
                'price' => $dto->price ?? null,
                'currency' => $dto->currency ?? null,
                'user_id' => $user->id,
                'status' => RequestStatus::OPEN->value,
            ]);

            // Dispatch matching job in background
            MatchRequestsJob::dispatch('delivery', $deliveryRequest->id);

            return response()->json($deliveryRequest);

        } catch (\Exception $e) {
            Log::error('Error creating delivery request', [
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

            $deliveryRequest = $this->deliveryRequestRepository->findByUserAndId($user, $id);

            if (!$deliveryRequest) {
                return response()->json(['error' => 'Request not found'], 404);
            }

            $this->requestService->deleteRequest($deliveryRequest);

            return response()->json(['message' => 'Request deleted successfully']);

        } catch (\Exception $e) {
            Log::error('Error deleting delivery request', [
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

            $deliveryRequest = $this->deliveryRequestRepository->findByUserAndId($user, $id);

            if (!$deliveryRequest) {
                return response()->json(['error' => 'Delivery request not found'], 404);
            }

            $this->requestService->closeRequest($deliveryRequest);

            return response()->json(['message' => 'Delivery request closed successfully']);

        } catch (\Exception $e) {
            Log::error('Error closing delivery request', [
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
            $deliveryRequests = $this->deliveryRequestRepository->findActiveByUser($user);

            return response()->json($deliveryRequests);

        } catch (\Exception $e) {
            Log::error('Error fetching user delivery requests', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);

            return response()->json(['error' => 'Failed to fetch requests'], 500);
        }
    }
}
