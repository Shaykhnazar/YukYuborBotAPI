<?php

namespace App\Http\Controllers;

use App\Enums\ResponseStatus;
use App\Enums\ResponseType;
use App\Http\Requests\Response\CreateManualResponseRequest;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\ResponseRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use App\Services\NotificationService;
use App\Services\Response\ResponseActionService;
use App\Services\Response\ResponseFormatterService;
use App\Services\Response\ResponseQueryService;
use App\Services\TelegramUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResponseController extends Controller
{
    public function __construct(
        protected TelegramUserService $tgService,
        protected ResponseQueryService $queryService,
        protected ResponseFormatterService $formatterService,
        protected ResponseActionService $actionService,
        protected NotificationService $notificationService,
        protected ResponseRepositoryInterface $responseRepository,
        protected SendRequestRepositoryInterface $sendRequestRepository,
        protected DeliveryRequestRepositoryInterface $deliveryRequestRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);
        $responses = $this->queryService->getUserResponses($user);

        $formattedResponses = [];

        foreach ($responses as $response) {
            if (!$this->queryService->canUserSeeResponse($response, $user->id)) {
                continue;
            }

            $formatted = $this->formatterService->formatResponse($response, $user);
            if ($formatted) {
                $formattedResponses[] = $formatted;
            }
        }

        return response()->json($formattedResponses);
    }

    public function createManual(CreateManualResponseRequest $request): JsonResponse
    {
        try {
            $user = $this->tgService->getUserByTelegramId($request);
            $validated = $request->validated();

            $targetRequest = $this->getTargetRequest($validated['offer_type'], $validated['request_id']);

            if (!$targetRequest || $targetRequest->user_id === $user->id) {
                return response()->json(['error' => 'Invalid request or cannot respond to own request'], 400);
            }

            if ($this->queryService->hasActiveResponse($targetRequest, $user, $validated['offer_type'], $validated['request_id'])) {
                return response()->json(['error' => 'You have already responded to this request'], 400);
            }

            $response = $this->createOrUpdateResponse($targetRequest, $user, $validated);

            $this->notificationService->sendResponseNotification(
                $targetRequest->user_id,
            );

            return response()->json([
                'message' => 'Manual response created successfully',
                'response_id' => $response->id
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating manual response', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'errorTitle' => 'Ошибка создания отклика'
            ], 422);
        }
    }

    public function accept(Request $request, int $responseId): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        if ($user->links_balance <= 0) {
            return response()->json(['error' => 'Insufficient links balance'], 403);
        }

        try {
            $response = $this->responseRepository->find($responseId);
            if (!$response) {
                return response()->json(['error' => 'Response not found'], 404);
            }

            if ($response->response_type === ResponseType::MANUAL->value) {
                $result = $this->actionService->acceptManualResponse($user, $responseId);
            } else {
                $result = $this->actionService->acceptMatchingResponse($user, $responseId);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error accepting response', [
                'response_id' => $responseId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'errorTitle' => 'Ошибка принятия отклика'
            ], 422);
        }
    }

    public function reject(Request $request, int $responseId): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        try {
            $response = $this->responseRepository->find($responseId);
            if (!$response) {
                return response()->json(['error' => 'Response not found'], 404);
            }

            if ($response->response_type === ResponseType::MANUAL->value) {
                $result = $this->actionService->rejectManualResponse($user, $responseId);
            } else {
                $result = $this->actionService->rejectMatchingResponse($user, $responseId);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error rejecting response', [
                'response_id' => $responseId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'errorTitle' => 'Ошибка отклонения отклика'
            ], 422);
        }
    }

    public function cancel(Request $request, int $responseId): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        try {
            $response = $this->responseRepository->find($responseId);
            if (!$response) {
                return response()->json(['error' => 'Response not found'], 404);
            }

            if ($response->response_type === ResponseType::MANUAL->value) {
                $result = $this->actionService->cancelManualResponse($user, $responseId);
            } else {
                $result = $this->actionService->cancelMatchingResponse($user, $responseId);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error cancelling response', [
                'response_id' => $responseId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'errorTitle' => 'Ошибка отмены отклика'
            ], 422);
        }
    }

    private function getTargetRequest(string $offerType, int $requestId)
    {
        return $offerType === 'send'
            ? $this->sendRequestRepository->find($requestId)
            : $this->deliveryRequestRepository->find($requestId);
    }

    private function createOrUpdateResponse($targetRequest, $user, array $validated)
    {
        $rejectedResponse = $this->queryService->findRejectedResponse($targetRequest, $user, $validated['offer_type'], $validated['request_id']);

        if ($rejectedResponse) {
            $this->responseRepository->update($rejectedResponse->id, [
                'overall_status' => ResponseStatus::PENDING->value,
                'message' => $validated['message'],
                'currency' => $validated['currency'] ?? null,
                'amount' => $validated['amount'] ?? null,
                'updated_at' => now()
            ]);
            return $rejectedResponse;
        }

        return $this->responseRepository->createManualResponse([
            'user_id' => $targetRequest->user_id,
            'responder_id' => $user->id,
            'offer_type' => $validated['offer_type'],
            'request_id' => 0,
            'offer_id' => $validated['request_id'],
            'message' => $validated['message'],
            'currency' => $validated['currency'] ?? null,
            'amount' => $validated['amount'] ?? null
        ]);
    }
}
