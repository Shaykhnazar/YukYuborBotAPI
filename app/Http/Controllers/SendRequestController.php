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
            
            // Check for duplicate route and date
            $this->requestService->checkDuplicateRoute(
                $user, 
                $dto->fromLocId, 
                $dto->toLocId, 
                $dto->toDate->toDateString(), 
                'send'
            );
            
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
        $request->validate([
            'response_id' => 'nullable|integer|exists:responses,id'
        ]);

        try {
            $user = $this->userService->getUserByTelegramId($request);

            $sendRequest = $this->sendRequestRepository->findByUserAndId($user, $id);

            if (!$sendRequest) {
                return response()->json(['error' => 'Send request not found'], 404);
            }

            $responseId = $request->input('response_id');

            // CRITICAL FIX: Support individual response closure for multiple response coexistence
            if ($responseId) {
                // Close specific response, not entire request
                $this->closeIndividualResponse($user, $sendRequest, $responseId);
                return response()->json(['message' => 'Response closed successfully']);
            } else {
                // Fallback: Close entire request (old behavior for backwards compatibility)
                $this->requestService->closeRequest($sendRequest);
                return response()->json(['message' => 'Send request closed successfully']);
            }

        } catch (\Exception $e) {
            Log::error('Error closing send request/response', [
                'request_id' => $id,
                'response_id' => $request->input('response_id'),
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

    /**
     * CRITICAL FIX: Close individual response, not entire request (for multiple response support)
     * Uses same logic as ChatController for consistency
     */
    private function closeIndividualResponse($user, $sendRequest, int $responseId): void
    {
        // Find the specific response
        $response = \App\Models\Response::where('id', $responseId)
            ->where(function($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->orWhere('responder_id', $user->id);
            })
            ->first();

        if (!$response) {
            throw new \Exception('Response not found or you do not have permission to close it');
        }

        // Update response status to closed
        $response->update([
            'overall_status' => 'closed',
            'deliverer_status' => 'closed',
            'sender_status' => 'closed',
            'updated_at' => now()
        ]);

        // CRITICAL: Check if this was the last active response for the request(s)
        $this->checkAndCloseRequestsIfNoActiveResponses($response, $user->name);

        Log::info('Individual response closed via request controller', [
            'response_id' => $response->id,
            'send_request_id' => $sendRequest->id,
            'closed_by' => $user->id
        ]);
    }

    /**
     * Check if request(s) should be closed when individual response is completed
     * Same logic as ChatController for consistency
     */
    private function checkAndCloseRequestsIfNoActiveResponses($response, string $completedByUserName): void
    {
        // For manual responses: check only the target request
        if ($response->response_type === 'manual') {
            $this->checkAndCloseRequestIfNoActiveResponses(
                $response->offer_type,
                $response->offer_id,
                $response->id,
                $completedByUserName
            );
        } else {
            // For matching responses: check both involved requests
            // Check the offered request (sender's request)
            $this->checkAndCloseRequestIfNoActiveResponses(
                $response->offer_type,
                $response->offer_id,
                $response->id,
                $completedByUserName
            );

            // Check the receiving request (deliverer's request)
            $receivingRequestType = $response->offer_type === 'send' ? 'delivery' : 'send';
            $this->checkAndCloseRequestIfNoActiveResponses(
                $receivingRequestType,
                $response->request_id,
                $response->id,
                $completedByUserName
            );
        }
    }

    /**
     * Check if a specific request should be closed when response is completed
     * Same logic as ChatController for consistency
     */
    private function checkAndCloseRequestIfNoActiveResponses(string $offerType, int $requestId, int $excludeResponseId, string $completedByUserName): void
    {
        // Check for ALL responses that involve this request
        $activeResponsesCount = \App\Models\Response::where(function($query) use ($offerType, $requestId) {
            // Case 1: Request is the offer (manual responses)
            $query->where(function($subQuery) use ($offerType, $requestId) {
                $subQuery->where('offer_type', $offerType)
                         ->where('offer_id', $requestId);
            });

            // Case 2: Request is the target (matching responses)
            $query->orWhere('request_id', $requestId);
        })
        ->where('id', '!=', $excludeResponseId)
        ->whereIn('overall_status', ['pending', 'partial', 'accepted'])
        ->count();

        // If no other active responses, close the request
        if ($activeResponsesCount === 0) {
            $requestModel = $offerType === 'send'
                ? \App\Models\SendRequest::find($requestId)
                : \App\Models\DeliveryRequest::find($requestId);

            if ($requestModel && !in_array($requestModel->status, ['closed', 'completed'])) {
                $requestModel->update(['status' => 'completed']);

                Log::info('Request closed automatically via controller - no more active responses', [
                    'request_type' => $offerType,
                    'request_id' => $requestId,
                    'completed_by' => $completedByUserName,
                    'reason' => 'All responses completed via controller'
                ]);

                // This will trigger RequestObserver which handles Google Sheets integration
            }
        }
    }
}