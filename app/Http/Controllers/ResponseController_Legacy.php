<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\User;
use App\Services\Matcher;
use App\Services\TelegramUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResponseController_Legacy extends Controller
{
    public function __construct(
        protected TelegramUserService $tgService,
        protected Matcher $matcher,
    ) {}

    /**
     * Get all responses for current user with new single response system
     * Shows responses where user is either receiver or responder
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        // Get all responses where user is involved (either as user or responder)
        $responses = Response::where(function($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->orWhere('responder_id', $user->id);
            })
            ->whereIn('overall_status', ['pending', 'partial', 'accepted'])
            ->with(['user.telegramUser', 'responder.telegramUser', 'chat'])
            ->orderByDesc('created_at')
            ->get();

        $formattedResponses = [];

        foreach ($responses as $response) {
            // Skip if user can't see this response yet
            if (!$this->canUserSeeResponse($response, $user->id)) {
                continue;
            }

            // Get the other user in this response and role information
            $otherUser = $response->user_id === $user->id ? $response->responder : $response->user;
            $userRole = $response->getUserRole($user->id);
            $userStatus = $response->getUserStatus($user->id);
            $canAct = $response->canUserTakeAction($user->id);
            $isReceiver = $response->user_id === $user->id;


            if ($response->offer_type === 'send') {
                // Get the send request (what user clicked on)
                $sendRequest = SendRequest::with(['fromLocation', 'toLocation'])->find($response->offer_id);

                if (!$sendRequest) continue;

                // For manual responses, we don't need a delivery request
                $deliveryRequest = null;
                if ($response->response_type === 'matching') {
                    $deliveryRequest = DeliveryRequest::with(['fromLocation', 'toLocation'])->find($response->request_id);
                    if (!$deliveryRequest) continue;
                }

                // Skip responses where request is closed
                if ($sendRequest->status === 'closed') continue;
                // For matching responses, also check delivery request status
                if ($deliveryRequest && $deliveryRequest->status === 'closed') continue;

                $responseId = $response->response_type === 'manual' ? $response->id : 'send_' . $response->offer_id . '_delivery_' . $response->request_id;

                $formattedResponses[] = [
                    'id' => $responseId,
                    'type' => 'send',
                    'request_id' => $response->request_id,
                    'offer_id' => $response->offer_id,
                    'chat_id' => $response->chat_id,
                    'can_act_on' => $canAct,
                    'user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'image' => $otherUser->telegramUser->image ?? null,
                        'requests_count' => $isReceiver
                            ? ($response->response_type === 'manual'
                                ? $otherUser->deliveryRequests()->closed()->count()
                                : $otherUser->sendRequests()->closed()->count())
                            : ($response->response_type === 'manual'
                                ? $otherUser->sendRequests()->closed()->count()
                                : $otherUser->deliveryRequests()->closed()->count()),
                    ],
                    'from_location' => $sendRequest->fromLocation->fullRouteName,
                    'to_location' => $sendRequest->toLocation->fullRouteName,
                    'from_date' => $sendRequest->from_date,
                    'to_date' => $sendRequest->to_date,
                    'price' => $response->response_type === 'manual' && $response->amount ? $response->amount : $sendRequest->price,
                    'currency' => $response->response_type === 'manual' && $response->currency ? $response->currency : $sendRequest->currency,
                    'size_type' => $sendRequest->size_type,
                    'description' => $response->response_type === 'manual' ? $response->message : $sendRequest->description,
                    'status' => $response->overall_status,
                    'user_status' => $userStatus,
                    'user_role' => $userRole,
                    'created_at' => $response->created_at,
                    'response_type' => $response->response_type === 'manual' ? 'manual' : 'can_deliver',
                    'direction' => $isReceiver ? 'received' : 'sent',
                    // Original send request info (for matching responses only)
                    'original_request' => $sendRequest ? [
                        'from_location' => $sendRequest->fromLocation->fullRouteName,
                        'to_location' => $sendRequest->toLocation->fullRouteName,
                        'description' => $sendRequest->description,
                        'price' => $sendRequest->price,
                        'currency' => $sendRequest->currency,
                    ] : null
                ];

            } elseif ($response->offer_type === 'delivery') {
                // Get the delivery request (what user clicked on)
                $deliveryRequest = DeliveryRequest::with(['fromLocation', 'toLocation'])->find($response->offer_id);

                if (!$deliveryRequest) continue;

                // For manual responses, we don't need a send request
                $sendRequest = null;
                if ($response->response_type === 'matching') {
                    $sendRequest = SendRequest::with(['fromLocation', 'toLocation'])->find($response->request_id);
                    if (!$sendRequest) continue;
                }

                // Skip responses where request is closed
                if ($deliveryRequest->status === 'closed') continue;
                // For matching responses, also check send request status
                if ($sendRequest && $sendRequest->status === 'closed') continue;

                $responseId = $response->response_type === 'manual' ? $response->id : 'delivery_' . $response->offer_id . '_send_' . $response->request_id;

                $formattedResponses[] = [
                    'id' => $responseId,
                    'type' => 'delivery',
                    'request_id' => $response->request_id,
                    'offer_id' => $response->offer_id,
                    'chat_id' => $response->chat_id,
                    'can_act_on' => $canAct,
                    'user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'image' => $otherUser->telegramUser->image ?? null,
                        'requests_count' => $isReceiver
                            ? ($response->response_type === 'manual'
                                ? $otherUser->sendRequests()->closed()->count()
                                : $otherUser->deliveryRequests()->closed()->count())
                            : ($response->response_type === 'manual'
                                ? $otherUser->deliveryRequests()->closed()->count()
                                : $otherUser->sendRequests()->closed()->count()),
                    ],
                    'from_location' => $deliveryRequest->fromLocation->fullRouteName,
                    'to_location' => $deliveryRequest->toLocation->fullRouteName,
                    'from_date' => $deliveryRequest->from_date,
                    'to_date' => $deliveryRequest->to_date,
                    'price' => $response->response_type === 'manual' && $response->amount ? $response->amount : $deliveryRequest->price,
                    'currency' => $response->response_type === 'manual' && $response->currency ? $response->currency : $deliveryRequest->currency,
                    'size_type' => $deliveryRequest->size_type,
                    'description' => $response->response_type === 'manual' ? $response->message : $deliveryRequest->description,
                    'status' => $response->overall_status,
                    'user_status' => $userStatus,
                    'user_role' => $userRole,
                    'created_at' => $response->created_at,
                    'response_type' => $response->response_type === 'manual' ? 'manual' : 'deliverer_responded',
                    'direction' => $isReceiver ? 'received' : 'sent',
                    // Original send request info (for matching responses only)
                    'original_request' => $sendRequest ? [
                        'from_location' => $sendRequest->fromLocation->fullRouteName,
                        'to_location' => $sendRequest->toLocation->fullRouteName,
                        'description' => $sendRequest->description,
                        'price' => $sendRequest->price,
                        'currency' => $sendRequest->currency,
                    ] : null
                ];
            }
        }

        return response()->json($formattedResponses);
    }

    /**
     * Create manual response - user manually responds to a request
     */
    public function createManual(Request $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        $validated = $request->validate([
            'offer_type' => 'required|in:send,delivery',
            'request_id' => 'required|integer',
            'message' => 'required|string',
            'currency' => 'nullable|string',
            'amount' => 'nullable|integer',
        ]);

        $requestType = $validated['offer_type'];
        $requestId = $validated['request_id'];
        $message = $validated['message'];

        // Get the target request
        if ($requestType === 'send') {
            $targetRequest = SendRequest::find($requestId);
        } else {
            $targetRequest = DeliveryRequest::find($requestId);
        }
        if (!$targetRequest || $targetRequest->user_id === $user->id) {
            return response()->json(['error' => 'Invalid request or cannot respond to own request'], 400);
        }

        // Check if user already has an active response to this request
        $activeResponse = Response::where(function($query) use ($targetRequest, $user, $requestType, $requestId) {
            $query->where(function($subQuery) use ($targetRequest, $user, $requestType, $requestId) {
                // Check if user already sent a response to this request owner
                $subQuery->where('user_id', $targetRequest->user_id)
                        ->where('responder_id', $user->id)
                        ->where('offer_type', $requestType)
                        ->where('offer_id', $requestId);
            })->orWhere(function($subQuery) use ($targetRequest, $user, $requestType, $requestId) {
                // Or check if user already has a response record for this request
                $subQuery->where('user_id', $user->id)
                        ->where('responder_id', $targetRequest->user_id)
                        ->where('offer_type', $requestType)
                        ->where('offer_id', $requestId);
            });
        })->whereIn('status', ['pending', 'waiting', 'accepted'])
          ->where('response_type', Response::TYPE_MANUAL)
          ->first();

        if ($activeResponse) {
            return response()->json(['error' => 'You have already responded to this request'], 400);
        }

        // Check if there's a rejected response that we can reuse
        $rejectedResponse = Response::where('user_id', $targetRequest->user_id)
            ->where('responder_id', $user->id)
            ->where('offer_type', $requestType)
            ->where('offer_id', $requestId)
            ->where('overall_status', Response::OVERALL_STATUS_REJECTED)
            ->where('response_type', Response::TYPE_MANUAL)
            ->first();

        // Either update existing rejected response or create new one
        if ($rejectedResponse) {
            // Reuse the rejected response by updating it
            $rejectedResponse->update([
                'overall_status' => Response::OVERALL_STATUS_PENDING,
                'message' => $message,
                'currency' => $validated['currency'] ?? null,
                'amount' => $validated['amount'] ?? null,
                'updated_at' => now()
            ]);
            $response = $rejectedResponse;
        } else {
            // Create new manual response for request owner (who receives and can accept/reject)
            $response = Response::create([
                'user_id' => $targetRequest->user_id, // Request owner receives the response
                'responder_id' => $user->id, // User who clicked "Откликнуться"
                'offer_type' => $requestType,
                'request_id' => 0, // Not used in manual responses
                'offer_id' => $requestId,
                'overall_status' => Response::OVERALL_STATUS_PENDING,
                'response_type' => Response::TYPE_MANUAL,
                'message' => $message,
                'currency' => $validated['currency'] ?? null,
                'amount' => $validated['amount'] ?? null
            ]);
        }

        // Send notification to request owner with response details
        $notificationMessage = "Пользователь откликнулся на вашу заявку!\n\n";
        $notificationMessage .= "💬 Сообщение: {$message}\n";

        if (!empty($validated['amount']) && !empty($validated['currency'])) {
            $notificationMessage .= "💰 Предложенная цена: {$validated['amount']} {$validated['currency']}\n";
        }

        $notificationMessage .= "\n📱 Проверьте отклики в приложении для ответа.";

        $this->sendTelegramNotification(
            $targetRequest->user_id,
            $user->name,
            $notificationMessage
        );

        return response()->json([
            'message' => 'Manual response created successfully',
            'response_id' => $response->id
        ]);
    }

    /**
     * Accept a response
     */
    public function accept(Request $request, string $responseId): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        // Check if user has enough links
        if ($user->links_balance <= 0) {
            return response()->json(['error' => 'Insufficient links balance'], 403);
        }

        // Handle manual responses (simple response ID)
        if (is_numeric($responseId)) {
            return $this->handleManualAcceptance($user, (int)$responseId);
        }

        // Handle matching responses (complex response ID)
        $parts = explode('_', $responseId);
        if (count($parts) !== 4) {
            return response()->json(['error' => 'Invalid response ID'], 400);
        }

        $type1 = $parts[0]; // 'send' or 'delivery'
        $id1 = $parts[1];
        $type2 = $parts[2]; // 'delivery' or 'send'
        $id2 = $parts[3];

        if ($type1 === 'send') {
            // Deliverer accepting send request
            return $this->handleDelivererAcceptance($user, $id1, $id2);
        } elseif ($type1 === 'delivery') {
            // Sender accepting deliverer response
            return $this->handleSenderAcceptance($user, $id2, $id1);
        }

        return response()->json(['error' => 'Invalid response type'], 400);
    }

    /**
     * Handle manual response acceptance (single confirmation)
     */
    private function handleManualAcceptance(User $user, int $responseId): JsonResponse
    {
        $response = Response::where('id', $responseId)
            ->where('user_id', $user->id)
            ->where('response_type', Response::TYPE_MANUAL)
            ->where('overall_status', Response::OVERALL_STATUS_PENDING)
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Manual response not found'], 404);
        }

        // Get the request and responder details
        if ($response->offer_type === 'send') {
            $targetRequest = SendRequest::find($response->offer_id);
        } else {
            $targetRequest = DeliveryRequest::find($response->offer_id);
        }

        if (!$targetRequest) {
            return response()->json(['error' => 'Target request not found'], 404);
        }

        $responder = User::find($response->responder_id);
        if (!$responder) {
            return response()->json(['error' => 'Responder not found'], 404);
        }

        // Create or find existing chat
        $chat = Chat::where(function($query) use ($user, $responder) {
            $query->where('sender_id', $user->id)
                ->where('receiver_id', $responder->id)
                ->orWhere('sender_id', $responder->id)
                ->where('receiver_id', $user->id);
        })->first();

        if (!$chat) {
            $chat = Chat::create([
                'sender_id' => $user->id,
                'receiver_id' => $responder->id,
                'send_request_id' => $response->offer_type === 'send' ? $response->offer_id : null,
                'delivery_request_id' => $response->offer_type === 'delivery' ? $response->offer_id : null,
                'status' => 'active',
            ]);
        } else {
            $chat->update(['status' => 'active']);
        }

        // Update response status to accepted (new system)
        $response->update([
            'chat_id' => $chat->id,
            // For manual responses, set both deliverer and sender as accepted since it's direct acceptance
            'deliverer_status' => Response::DUAL_STATUS_ACCEPTED,
            'sender_status' => Response::DUAL_STATUS_ACCEPTED,
            'overall_status' => Response::OVERALL_STATUS_ACCEPTED,
        ]);

        // Update target request status
        $targetRequest->update(['status' => 'matched_manually']);

        // Send notification to responder
        $this->sendTelegramNotification(
            $responder->id,
            $user->name,
            "Отлично! Ваш отклик принят. Теперь вы можете общаться в чате."
        );

        return response()->json([
            'chat_id' => $chat->id,
            'message' => 'Manual response accepted successfully'
        ]);
    }

    /**
     * Handle manual response rejection
     */
    private function handleManualRejection(User $user, int $responseId): JsonResponse
    {
        $response = Response::where('id', $responseId)
            ->where('user_id', $user->id)
            ->where('response_type', Response::TYPE_MANUAL)
            ->where('overall_status', Response::OVERALL_STATUS_PENDING)
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Manual response not found'], 404);
        }

        // Update response status to rejected (new system)
        $response->update([
            'overall_status' => Response::OVERALL_STATUS_REJECTED,
            // Keep deliverer_status and sender_status as they were (likely 'pending')
        ]);

        // Send notification to responder (optional)
        $this->sendTelegramNotification(
            $response->responder_id,
            $user->name,
            "К сожалению, ваш отклик был отклонен."
        );

        return response()->json(['message' => 'Manual response rejected successfully']);
    }

    /**
     * Handle deliverer accepting a send request
     */
    private function handleDelivererAcceptance(User $deliverer, int $sendRequestId, int $deliveryRequestId): JsonResponse
    {
//        Log::info('Deliverer acceptance started', [
//            'deliverer_id' => $deliverer->id,
//            'send_request_id' => $sendRequestId,
//            'delivery_request_id' => $deliveryRequestId
//        ]);

        $sendRequest = SendRequest::find($sendRequestId);
        $deliveryRequest = DeliveryRequest::find($deliveryRequestId);

        if (!$sendRequest || !$deliveryRequest) {
            Log::error('Requests not found', [
                'send_request_found' => !!$sendRequest,
                'delivery_request_found' => !!$deliveryRequest
            ]);
            return response()->json(['error' => 'Request not found'], 404);
        }

        // NEW SYSTEM: Find the single response record
        $response = Response::where(function($query) use ($sendRequest, $deliveryRequest) {
            $query->where('request_id', $sendRequest->id)
                  ->where('offer_id', $deliveryRequest->id)
                  ->where('offer_type', 'delivery');
        })
        ->orWhere(function($query) use ($sendRequest, $deliveryRequest) {
            $query->where('request_id', $deliveryRequest->id)
                  ->where('offer_id', $sendRequest->id)
                  ->where('offer_type', 'send');
        })
        ->where('response_type', Response::TYPE_MATCHING)
        ->whereIn('overall_status', ['pending', 'partial'])
        ->first();

        Log::info('NEW SYSTEM: Looking for response record', [
            'deliverer_id' => $deliverer->id,
            'send_request_id' => $sendRequest->id,
            'delivery_request_id' => $deliveryRequest->id,
            'found' => !!$response,
            'response_id' => $response ? $response->id : null
        ]);

        if (!$response) {
            Log::warning('NEW SYSTEM: Response not found for deliverer acceptance', [
                'deliverer_id' => $deliverer->id,
                'send_request_id' => $sendRequest->id,
                'delivery_request_id' => $deliveryRequest->id
            ]);
            return response()->json(['error' => 'Response not found or already processed'], 404);
        }

        // Check if deliverer can take action
        if (!$response->canUserTakeAction($deliverer->id)) {
            return response()->json(['error' => 'Cannot accept this response'], 403);
        }

        try {
            // NEW SYSTEM: Update deliverer's status and handle related status changes
            $matcherResult = $this->matcher->handleUserResponse($response->id, $deliverer->id, 'accept');

            if (!$matcherResult) {
                Log::error('Matcher failed to handle deliverer acceptance', [
                    'response_id' => $response->id,
                    'deliverer_id' => $deliverer->id
                ]);
                return response()->json(['error' => 'Failed to process acceptance'], 500);
            }

            Log::info('Deliverer accepted via Matcher service', [
                'response_id' => $response->id,
                'deliverer_id' => $deliverer->id,
                'deliverer_status' => $response->fresh()->deliverer_status,
                'overall_status' => $response->fresh()->overall_status
            ]);

            $response->refresh();

            // Check if both users have now accepted
            if ($response->overall_status === 'accepted') {
                return response()->json([
                    'message' => 'Both users accepted - partnership confirmed!',
                    'chat_id' => $response->chat_id
                ]);
            } else {
                return response()->json([
                    'message' => 'Response sent to sender for confirmation',
                    'status' => 'partial'
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error in deliverer acceptance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Handle sender accepting deliverer response (final step)
     */
    private function handleSenderAcceptance(User $sender, int $sendRequestId, int $deliveryRequestId): JsonResponse
    {
//        Log::info('🎯 Starting sender acceptance process', [
//            'sender_id' => $sender->id,
//            'send_request_id' => $sendRequestId,
//            'delivery_request_id' => $deliveryRequestId
//        ]);

        $sendRequest = SendRequest::find($sendRequestId);
        $deliveryRequest = DeliveryRequest::find($deliveryRequestId);

        if (!$sendRequest || !$deliveryRequest) {
            Log::error('❌ Requests not found', [
                'send_request_found' => !!$sendRequest,
                'delivery_request_found' => !!$deliveryRequest
            ]);
            return response()->json(['error' => 'Request not found'], 404);
        }

        // NEW SYSTEM: Find the single response record
        $response = Response::where(function($query) use ($sendRequest, $deliveryRequest) {
            $query->where('request_id', $sendRequest->id)
                  ->where('offer_id', $deliveryRequest->id)
                  ->where('offer_type', 'delivery');
        })
        ->orWhere(function($query) use ($sendRequest, $deliveryRequest) {
            $query->where('request_id', $deliveryRequest->id)
                  ->where('offer_id', $sendRequest->id)
                  ->where('offer_type', 'send');
        })
        ->where('response_type', Response::TYPE_MATCHING)
        ->whereIn('overall_status', ['pending', 'partial'])
        ->first();

        Log::info('NEW SYSTEM: Looking for sender response record', [
            'sender_id' => $sender->id,
            'send_request_id' => $sendRequest->id,
            'delivery_request_id' => $deliveryRequest->id,
            'found' => !!$response,
            'response_id' => $response ? $response->id : null
        ]);

        if (!$response) {
            Log::warning('NEW SYSTEM: Response not found for sender acceptance', [
                'sender_id' => $sender->id,
                'send_request_id' => $sendRequest->id,
                'delivery_request_id' => $deliveryRequest->id
            ]);
            return response()->json(['error' => 'Response not found or already processed'], 404);
        }

        // Check if sender can take action
        if (!$response->canUserTakeAction($sender->id)) {
            return response()->json(['error' => 'Cannot accept this response'], 403);
        }

        try {
            DB::beginTransaction();

            // NEW SYSTEM: Update sender's status and handle related status changes
            $matcherResult = $this->matcher->handleUserResponse($response->id, $sender->id, 'accept');

            if (!$matcherResult) {
                Log::error('Matcher failed to handle sender acceptance', [
                    'response_id' => $response->id,
                    'sender_id' => $sender->id
                ]);
                DB::rollBack();
                return response()->json(['error' => 'Failed to process acceptance'], 500);
            }

            Log::info('NEW SYSTEM: Sender accepted via Matcher service', [
                'response_id' => $response->id,
                'sender_id' => $sender->id,
                'sender_status' => $response->fresh()->sender_status,
                'overall_status' => $response->fresh()->overall_status
            ]);

            $response->refresh();

            // If both users have accepted, finalize the match
            if ($response->overall_status === 'accepted') {
                // Update both request statuses to matched
                $sendRequest->update(['status' => 'matched']);
                $deliveryRequest->update(['status' => 'matched']);

                // Deduct links from sender
                $sender->decrement('links_balance', 1);

                Log::info('NEW SYSTEM: Both users accepted - partnership finalized', [
                    'response_id' => $response->id,
                    'chat_id' => $response->chat_id
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'Partnership confirmed successfully!',
                    'chat_id' => $response->chat_id,
                    'status' => 'accepted'
                ]);
            } else {
                DB::commit();

                return response()->json([
                    'message' => 'Sender acceptance recorded. Waiting for deliverer confirmation.',
                    'status' => 'partial',
                    'overall_status' => $response->overall_status
                ]);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('NEW SYSTEM: Error in sender acceptance', [
                'error' => $e->getMessage(),
                'response_id' => $response->id
            ]);
            return response()->json(['error' => 'Failed to process acceptance'], 500);
        }
    }

    /**
     * Reject a response
     */
    public function reject(Request $request, string $responseId): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        // Handle manual responses (simple response ID)
        if (is_numeric($responseId)) {
            return $this->handleManualRejection($user, (int)$responseId);
        }

        // Handle matching responses (complex response ID)
        $parts = explode('_', $responseId);
        if (count($parts) !== 4) {
            return response()->json(['error' => 'Invalid response ID'], 400);
        }

        $type1 = $parts[0];
        $id1 = $parts[1];
        $type2 = $parts[2];
        $id2 = $parts[3];

        if ($type1 === 'send') {
            // Deliverer rejecting send request
            $sendRequestId = $id1;
            $deliveryRequestId = $id2;

            $response = Response::where('user_id', $user->id)
                ->where('offer_type', 'send')
                ->where('request_id', $deliveryRequestId)
                ->where('offer_id', $sendRequestId)
                ->where('overall_status', 'pending')
                ->first();

            if ($response) {
                $response->updateUserStatus($user->id, 'rejected');

                // Reset delivery request status if no other active responses exist
                $deliveryRequest = DeliveryRequest::find($deliveryRequestId);
                if ($deliveryRequest && $deliveryRequest->status !== 'open') {
                    $hasOtherResponses = Response::where('user_id', $deliveryRequest->user_id)
                        ->where('offer_type', 'send')
                        ->where('request_id', $deliveryRequestId)
                        ->whereIn('status', ['pending', 'waiting'])
                        ->where('id', '!=', $response->id)
                        ->exists();

                    $newStatus = $hasOtherResponses ? 'has_responses' : 'open';
                    $deliveryRequest->update(['status' => $newStatus]);
                }

                // Notify sender that deliverer rejected
//                $sendRequest = SendRequest::find($sendRequestId);
//                if ($sendRequest) {
//                    $this->sendTelegramNotification(
//                        $sendRequest->user_id,
//                        $user->name,
//                        "К сожалению, один из перевозчиков отклонил вашу посылку. Мы продолжаем поиск."
//                    );
//                }
            }

        } elseif ($type1 === 'delivery') {
            // Sender rejecting deliverer response
            $deliveryRequestId = $id1;
            $sendRequestId = $id2;

            // Find the waiting response record
            $response = Response::where('user_id', $user->id)
                ->where('offer_type', 'delivery')
                ->where('request_id', $sendRequestId)
                ->where('offer_id', $deliveryRequestId)
                ->where('overall_status', 'waiting')
                ->first();

            if ($response) {
                // Mark this response as rejected
                $response->updateUserStatus($user->id, 'rejected');

                // Get the delivery request to find the deliverer's user ID
                $deliveryRequest = DeliveryRequest::find($deliveryRequestId);

                if ($deliveryRequest) {
                    // Find and reject the original deliverer's response using the correct user_id
                    $delivererResponse = Response::where('user_id', $deliveryRequest->user_id)
                        ->where('offer_type', 'send')
                        ->where('offer_id', $sendRequestId)
                        ->whereIn('status', ['responded', 'accepted'])
                        ->first();

                    if ($delivererResponse) {
                        $delivererResponse->updateUserStatus($user->id, 'rejected');
                    }

                    // Notify deliverer that sender rejected
//                    $this->sendTelegramNotification(
//                        $deliveryRequest->user_id,
//                        $user->name,
//                        "К сожалению, отправитель выбрал другого перевозчика для своей посылки."
//                    );
                }

                // Reset the send request status - check for other active responses
                $sendRequest = SendRequest::find($sendRequestId);
                if ($sendRequest && $sendRequest->status !== 'open') {
                    $hasOtherResponses = Response::where('offer_type', 'delivery')
                        ->where('request_id', $sendRequestId)
                        ->whereIn('status', ['pending', 'waiting'])
                        ->where('id', '!=', $response->id)
                        ->exists();

                    $newStatus = $hasOtherResponses ? 'has_responses' : 'open';
                    $sendRequest->update(['status' => $newStatus]);
                }

                // Reset the delivery request status - check for other active responses
                if ($deliveryRequest && $deliveryRequest->status !== 'open') {
                    $hasOtherResponses = Response::where('user_id', $deliveryRequest->user_id)
                        ->where('offer_type', 'send')
                        ->where('request_id', $deliveryRequestId)
                        ->whereIn('status', ['pending', 'waiting'])
                        ->exists();

                    $newStatus = $hasOtherResponses ? 'has_responses' : 'open';
                    $deliveryRequest->update(['status' => $newStatus]);
                }
            }
        }

        return response()->json(['message' => 'Response rejected']);
    }

    /**
     * Cancel a response
     */
    public function cancel(Request $request, string $responseId): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        // Handle manual responses (simple response ID)
        if (is_numeric($responseId)) {
            return $this->handleManualCancellation($user, (int)$responseId);
        }

        // Handle matching responses (complex response ID)
        $parts = explode('_', $responseId);
        if (count($parts) !== 4) {
            return response()->json(['error' => 'Invalid response ID'], 400);
        }

        $offerType = $parts[0];
        $offerId = $parts[1];
        $requestType = $parts[2];
        $requestId = $parts[3];

        // Find the response record that the user wants to cancel
        // User can cancel responses where they are either the receiver (user_id) or the responder (responder_id)
        $response = Response::where(function($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->orWhere('responder_id', $user->id);
            })
            ->where('offer_type', $offerType)
            ->where('request_id', $requestId)
            ->where('offer_id', $offerId)
            ->whereIn('overall_status', ['waiting', 'accepted', 'pending', 'responded'])
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Response not found'], 404);
        }

        // Store response details before deletion for status management
        $responseOfferType = $response->offer_type;
        $responseOfferId = $response->offer_id;
        $responseRequestId = $response->request_id;

        // Delete the response record for cancellation
        $response->delete();

        // Update request statuses based on remaining responses
        $this->updateRequestStatusAfterCancellation($responseOfferType, $responseOfferId, $responseRequestId);

        return response()->json(['message' => 'Response cancelled']);
    }

    /**
     * Handle manual response cancellation
     */
    private function handleManualCancellation(User $user, int $responseId): JsonResponse
    {
        // Find the manual response that the responder wants to cancel
        $response = Response::where('id', $responseId)
            ->where('responder_id', $user->id) // User must be the responder (who created the response)
            ->where('response_type', Response::TYPE_MANUAL)
            ->whereIn('overall_status', [Response::OVERALL_STATUS_PENDING, Response::OVERALL_STATUS_PARTIAL])
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Manual response not found or cannot be cancelled'], 404);
        }

        // Store response details before deletion for status management
        $responseOfferType = $response->offer_type;
        $responseOfferId = $response->offer_id;
        $responseRequestId = $response->request_id;

        // Delete the response record for cancellation
        $response->delete();

        // Update request status based on remaining responses for manual responses
        $this->updateRequestStatusAfterManualCancellation($responseOfferType, $responseOfferId);

        // Send notification to request owner that response was cancelled
//        $this->sendTelegramNotification(
//            $response->user_id,
//            $user->name,
//            "Пользователь отменил свой отклик на вашу заявку."
//        );

        return response()->json(['message' => 'Manual response cancelled successfully']);
    }

    /**
     * Update request status after matching response cancellation
     */
    private function updateRequestStatusAfterCancellation(string $offerType, int $offerId, int $requestId): void
    {
        // Update the offer request status
        if ($offerType === 'send') {
            $sendRequest = SendRequest::find($offerId);
            if ($sendRequest && $sendRequest->status !== 'open') {
                // Check if there are any remaining active responses
                $hasOtherResponses = Response::where('offer_id', $offerId)
                    ->where('offer_type', 'send')
                    ->whereIn('overall_status', ['pending', 'waiting', 'responded'])
                    ->exists();

                $newStatus = $hasOtherResponses ? 'has_responses' : 'open';
                $sendRequest->update(['status' => $newStatus]);
            }
        } elseif ($offerType === 'delivery') {
            $deliveryRequest = DeliveryRequest::find($offerId);
            if ($deliveryRequest && $deliveryRequest->status !== 'open') {
                // Check if there are any remaining active responses
                $hasOtherResponses = Response::where('offer_id', $offerId)
                    ->where('offer_type', 'delivery')
                    ->whereIn('status', ['pending', 'waiting', 'responded'])
                    ->exists();

                $newStatus = $hasOtherResponses ? 'has_responses' : 'open';
                $deliveryRequest->update(['status' => $newStatus]);
            }
        }

        // Update the main request status
        if ($offerType === 'send') {
            // For send offers, the main request is a delivery request
            $deliveryRequest = DeliveryRequest::find($requestId);
            if ($deliveryRequest && $deliveryRequest->status !== 'open') {
                // Check if there are any remaining active responses for this delivery request
                $hasOtherResponses = Response::where('request_id', $requestId)
                    ->where('offer_type', 'send')
                    ->whereIn('status', ['pending', 'waiting', 'responded'])
                    ->exists();

                $newStatus = $hasOtherResponses ? 'has_responses' : 'open';
                $deliveryRequest->update(['status' => $newStatus]);
            }
        } elseif ($offerType === 'delivery') {
            // For delivery offers, the main request is a send request
            $sendRequest = SendRequest::find($requestId);
            if ($sendRequest && $sendRequest->status !== 'open') {
                // Check if there are any remaining active responses for this send request
                $hasOtherResponses = Response::where('request_id', $requestId)
                    ->where('offer_type', 'delivery')
                    ->whereIn('status', ['pending', 'waiting', 'responded'])
                    ->exists();

                $newStatus = $hasOtherResponses ? 'has_responses' : 'open';
                $sendRequest->update(['status' => $newStatus]);
            }
        }
    }

    /**
     * Update request status after manual response cancellation
     */
    private function updateRequestStatusAfterManualCancellation(string $offerType, int $offerId): void
    {
        if ($offerType === 'send') {
            $sendRequest = SendRequest::find($offerId);
            if ($sendRequest && $sendRequest->status !== 'open') {
                // Check if there are any remaining manual responses
                $hasOtherResponses = Response::where('offer_id', $offerId)
                    ->where('offer_type', 'send')
                    ->where('response_type', Response::TYPE_MANUAL)
                    ->whereIn('status', ['pending', 'waiting'])
                    ->exists();

                $newStatus = $hasOtherResponses ? 'has_responses' : 'open';
                $sendRequest->update(['status' => $newStatus]);
            }
        } elseif ($offerType === 'delivery') {
            $deliveryRequest = DeliveryRequest::find($offerId);
            if ($deliveryRequest && $deliveryRequest->status !== 'open') {
                // Check if there are any remaining manual responses
                $hasOtherResponses = Response::where('offer_id', $offerId)
                    ->where('offer_type', 'delivery')
                    ->where('response_type', Response::TYPE_MANUAL)
                    ->whereIn('status', ['pending', 'waiting'])
                    ->exists();

                $newStatus = $hasOtherResponses ? 'has_responses' : 'open';
                $deliveryRequest->update(['status' => $newStatus]);
            }
        }
    }

    /**
     * Check if user can see this response based on new single response system
     */
    private function canUserSeeResponse(Response $response, int $userId): bool
    {
        // For manual responses, both users can always see
        if ($response->response_type === Response::TYPE_MANUAL) {
            return true;
        }

        // For matching responses with new dual acceptance system
        $userRole = $response->getUserRole($userId);

        if ($userRole === 'unknown') {
            return false;
        }

        // User can see the response if:
        // 1. Overall status is pending (both can see it's available)
        // 2. Overall status is partial and they haven't acted yet
        // 3. Overall status is accepted (both can see final result)
        return in_array($response->overall_status, ['pending', 'partial', 'accepted']);
    }

    /**
     * Send Telegram notification to user
     */
    private function sendTelegramNotification(int $userId, string $senderName, string $message): void
    {
        $user = User::with('telegramUser')->find($userId);

        if (!$user || !$user->telegramUser) {
            return;
        }

        $telegramId = $user->telegramUser->telegram;
        $notificationText = "📬 {$message}";

        $token = config('auth.guards.tgwebapp.token');
        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $telegramId,
            'text' => $notificationText,
        ]);

        if ($response->failed()) {
            Log::error('Failed to send Telegram notification', [
                'user_id' => $userId,
                'telegram_id' => $telegramId,
                'response' => $response->body()
            ]);
        }
    }
}
