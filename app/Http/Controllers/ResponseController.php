<?php

namespace App\Http\Controllers;

use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Models\User;
use App\Models\Chat;
use App\Models\Response;
use App\Service\TelegramUserService;
use App\Service\Matcher;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResponseController extends Controller
{
    public function __construct(
        protected TelegramUserService $tgService,
        protected Matcher $matcher,
    ) {}

    /**
     * Get all responses for current user
     * Shows both:
     * 1. Responses received by user (where they can accept/reject)
     * 2. Responses sent by user (where they can only view status)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        // Get responses received by user (where they can accept/reject)
        $receivedResponses = Response::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'waiting', 'accepted', 'responded'])
            ->with(['responder.telegramUser', 'chat'])
            ->orderByDesc('created_at')
            ->get();

        // Get responses sent by user (where they are the responder)
        $sentResponses = Response::where('responder_id', $user->id)
            ->whereIn('status', ['pending', 'waiting', 'accepted', 'responded'])
            ->with(['user.telegramUser', 'chat'])
            ->orderByDesc('created_at')
            ->get();

        $responses = $receivedResponses->merge($sentResponses);

        $formattedResponses = [];

        foreach ($responses as $response) {
            // Determine if user is the receiver (can act) or sender (view only)
            $isReceiver = $response->user_id === $user->id;
            $otherUser = $isReceiver ? $response->responder : $response->user;

            // For matching responses, control visibility based on status and user role
            if ($response->response_type === Response::TYPE_MATCHING) {
                if ($response->status === 'pending') {
                  // Deliverer should see first
                  if ($response->user_id !== $user->id) {
                      continue; // Skip for sender
                  }
                } elseif ($response->status === 'waiting') {
                    // Show if user is not responder
                    if ($response->responder_id === $user->id) {
                        continue; // Skip if user is the response receiver
                    }
                } elseif ($response->status === 'responded') {
                    // Show responded status to the user who received the response (deliverer who owns the request)
                    if ($response->user_id !== $user->id) {
                        continue; // Skip if user is not the response receiver
                    }
                } elseif ($response->status === 'accepted') {
                    // Each user sees other user's request record
                    if ($response->user_id !== $user->id) {
                        continue; // Skip if user's own request
                    }
                }
            }


            if ($response->request_type === 'send') {
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
                    'can_act_on' => $response->response_type === Response::TYPE_MATCHING
                        ? ($response->status === 'pending' ? $response->user_id === $user->id
                            : ($response->status === 'waiting' && $response->user_id === $user->id))
                        : $isReceiver,
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
                    'status' => $response->status,
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

            } elseif ($response->request_type === 'delivery') {
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
                    'can_act_on' => $response->response_type === Response::TYPE_MATCHING
                          ? ($response->status === 'pending' ? $response->user_id === $user->id
                              : ($response->status === 'waiting' && $response->user_id === $user->id))
                          : $isReceiver,
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
                    'status' => $response->status,
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
            'request_type' => 'required|in:send,delivery',
            'request_id' => 'required|integer',
            'message' => 'required|string',
            'currency' => 'nullable|string',
            'amount' => 'nullable|integer',
        ]);

        $requestType = $validated['request_type'];
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
                        ->where('request_type', $requestType)
                        ->where('offer_id', $requestId);
            })->orWhere(function($subQuery) use ($targetRequest, $user, $requestType, $requestId) {
                // Or check if user already has a response record for this request
                $subQuery->where('user_id', $user->id)
                        ->where('responder_id', $targetRequest->user_id)
                        ->where('request_type', $requestType)
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
            ->where('request_type', $requestType)
            ->where('offer_id', $requestId)
            ->where('status', Response::STATUS_REJECTED)
            ->where('response_type', Response::TYPE_MANUAL)
            ->first();

        // Either update existing rejected response or create new one
        if ($rejectedResponse) {
            // Reuse the rejected response by updating it
            $rejectedResponse->update([
                'status' => Response::STATUS_PENDING,
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
                'request_type' => $requestType,
                'request_id' => 0, // Not used in manual responses
                'offer_id' => $requestId,
                'status' => Response::STATUS_PENDING,
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
            ->where('status', Response::STATUS_PENDING)
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Manual response not found'], 404);
        }

        // Get the request and responder details
        if ($response->request_type === 'send') {
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
                'send_request_id' => $response->request_type === 'send' ? $response->offer_id : null,
                'delivery_request_id' => $response->request_type === 'delivery' ? $response->offer_id : null,
                'status' => 'active',
            ]);
        } else {
            $chat->update(['status' => 'active']);
        }

        // Update response status to accepted
        $response->update([
            'status' => Response::STATUS_ACCEPTED,
            'chat_id' => $chat->id
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
            ->where('status', Response::STATUS_PENDING)
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Manual response not found'], 404);
        }

        // Update response status to rejected
        $response->update(['status' => Response::STATUS_REJECTED]);

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
        Log::info('Deliverer acceptance started', [
            'deliverer_id' => $deliverer->id,
            'send_request_id' => $sendRequestId,
            'delivery_request_id' => $deliveryRequestId
        ]);

        $sendRequest = SendRequest::find($sendRequestId);
        $deliveryRequest = DeliveryRequest::find($deliveryRequestId);

        if (!$sendRequest || !$deliveryRequest) {
            Log::error('Requests not found', [
                'send_request_found' => !!$sendRequest,
                'delivery_request_found' => !!$deliveryRequest
            ]);
            return response()->json(['error' => 'Request not found'], 404);
        }

        // Find the response record for the deliverer
        $response = Response::where('user_id', $deliverer->id)
            ->where('request_type', 'send')
            ->where('request_id', $deliveryRequest->id)
            ->where('offer_id', $sendRequest->id)
            ->where('status', 'pending')
            ->first();

        Log::info('Looking for response record', [
            'user_id' => $deliverer->id,
            'request_type' => 'send',
            'request_id' => $deliveryRequest->id,
            'offer_id' => $sendRequest->id,
            'status' => 'pending',
            'found' => !!$response
        ]);

        if (!$response) {
            // Let's see what responses exist for this user
            $existingResponses = Response::where('user_id', $deliverer->id)
                ->where('request_id', $deliveryRequest->id)
                ->where('offer_id', $sendRequest->id)
                ->get();

            Log::error('Response not found - existing responses:', [
                'existing_responses' => $existingResponses->toArray()
            ]);

            return response()->json(['error' => 'Response not found'], 404);
        }

        try {
            // Update deliverer's response to 'responded'
            $response->update(['status' => 'responded']);
            Log::info('Response status updated to responded');

            // Create response for sender and notify them
            $this->matcher->createDelivererResponse($sendRequest->id, $deliveryRequest->id, 'accept');
            Log::info('Deliverer response created successfully');

            return response()->json(['message' => 'Response sent to sender for confirmation']);

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
        Log::info('🎯 Starting sender acceptance process', [
            'sender_id' => $sender->id,
            'send_request_id' => $sendRequestId,
            'delivery_request_id' => $deliveryRequestId
        ]);

        $sendRequest = SendRequest::find($sendRequestId);
        $deliveryRequest = DeliveryRequest::find($deliveryRequestId);

        if (!$sendRequest || !$deliveryRequest) {
            Log::error('❌ Requests not found', [
                'send_request_found' => !!$sendRequest,
                'delivery_request_found' => !!$deliveryRequest
            ]);
            return response()->json(['error' => 'Request not found'], 404);
        }

        // Find the waiting response record (sender's response)
        $senderResponse = Response::where('user_id', $sender->id)
            ->where('request_type', 'delivery')
            ->where('request_id', $sendRequest->id)
            ->where('offer_id', $deliveryRequest->id)
            ->where('status', 'waiting')
            ->first();

        if (!$senderResponse) {
            Log::warning('❌ Response not found for sender acceptance', [
                'user_id' => $sender->id,
                'send_request_id' => $sendRequest->id,
                'delivery_request_id' => $deliveryRequest->id
            ]);
            return response()->json(['error' => 'Response not found or already processed'], 404);
        }

        Log::info('✅ Found sender response to update', ['response_id' => $senderResponse->id]);

        // ✅ ENHANCED: Better chat finding/creation logic
        $existingChat = Chat::where(function($query) use ($sender, $deliveryRequest) {
            $query->where('sender_id', $sender->id)
                ->where('receiver_id', $deliveryRequest->user_id)
                ->orWhere('sender_id', $deliveryRequest->user_id)
                ->where('receiver_id', $sender->id);
        })
        ->orderByDesc('created_at') // ✅ Get most recent chat
        ->first();

        $chat = null;
        $isNewChat = false;

        if ($existingChat) {
            Log::info('📞 Found existing chat', [
                'chat_id' => $existingChat->id,
                'current_status' => $existingChat->status,
                'current_send_request_id' => $existingChat->send_request_id,
                'current_delivery_request_id' => $existingChat->delivery_request_id
            ]);

            $chat = $existingChat;

            // ✅ ALWAYS reopen and update references
            $updateData = ['status' => 'active'];
            if (!$existingChat->send_request_id) {
                $updateData['send_request_id'] = $sendRequest->id;
            }
            if (!$existingChat->delivery_request_id) {
                $updateData['delivery_request_id'] = $deliveryRequest->id;
            }

            Log::info('🔄 Updating existing chat', [
                'chat_id' => $existingChat->id,
                'update_data' => $updateData
            ]);

            $chat->update($updateData);

        } else {
            Log::info('🆕 Creating new chat between users', [
                'sender_id' => $sender->id,
                'receiver_id' => $deliveryRequest->user_id
            ]);

            $chat = Chat::create([
                'sender_id' => $sender->id,
                'receiver_id' => $deliveryRequest->user_id,
                'send_request_id' => $sendRequest->id,
                'delivery_request_id' => $deliveryRequest->id,
                'status' => 'active',
            ]);
            $isNewChat = true;

            Log::info('✅ Created new chat', [
                'chat_id' => $chat->id,
                'sender_id' => $chat->sender_id,
                'receiver_id' => $chat->receiver_id,
                'send_request_id' => $chat->send_request_id,
                'delivery_request_id' => $chat->delivery_request_id,
                'status' => $chat->status
            ]);
        }

        // Update sender's response status to accepted
        $senderResponse->update([
            'status' => 'accepted',
            'chat_id' => $chat->id
        ]);

        Log::info('✅ Updated sender response', [
            'response_id' => $senderResponse->id,
            'new_status' => 'accepted',
            'chat_id' => $chat->id
        ]);

        // ✅ CRITICAL: Also update the deliverer's response status to accepted
        $delivererResponse = Response::where('user_id', $deliveryRequest->user_id)
            ->where('request_type', 'send')
            ->where('request_id', $deliveryRequest->id)
            ->where('offer_id', $sendRequest->id)
            ->where('status', 'responded')
            ->first();

        if ($delivererResponse) {
            $delivererResponse->update([
                'status' => 'accepted',
                'chat_id' => $chat->id
            ]);
            Log::info('✅ Updated deliverer response', [
                'response_id' => $delivererResponse->id,
                'new_status' => 'accepted',
                'chat_id' => $chat->id
            ]);
        } else {
            Log::warning('⚠️ Deliverer response not found for status update', [
                'deliverer_user_id' => $deliveryRequest->user_id,
                'delivery_request_id' => $deliveryRequest->id,
                'send_request_id' => $sendRequest->id
            ]);
        }

        // Update request statuses
        $sendRequest->update(['status' => 'matched', 'matched_delivery_id' => $deliveryRequest->id]);
        $deliveryRequest->update(['status' => 'matched', 'matched_send_id' => $sendRequest->id]);

        Log::info('✅ Updated request statuses to matched', [
            'send_request_id' => $sendRequest->id,
            'delivery_request_id' => $deliveryRequest->id
        ]);

        // Reject all other pending responses for both requests
        $rejectedCount = Response::where(function($query) use ($sendRequest, $deliveryRequest) {
            $query->where('offer_id', $sendRequest->id)
                  ->orWhere('request_id', $deliveryRequest->id)
                  ->orWhere('offer_id', $deliveryRequest->id)
                  ->orWhere('request_id', $sendRequest->id);
        })
        ->whereIn('status', ['pending', 'waiting'])
        ->where('id', '!=', $senderResponse->id)
        ->update(['status' => 'rejected']);

        Log::info('✅ Rejected other pending responses', ['count' => $rejectedCount]);

        // Send notification to deliverer
        $this->sendTelegramNotification(
            $deliveryRequest->user_id,
            $sender->name,
            "Отлично! Отправитель подтвердил сотрудничество. Теперь вы можете общаться в чате для уточнения деталей доставки."
        );

        Log::info('🎉 Sender acceptance process completed successfully', [
            'chat_id' => $chat->id,
            'is_new_chat' => $isNewChat,
            'chat_status' => $chat->status
        ]);

        return response()->json([
            'chat_id' => $chat->id,
            'message' => 'Partnership confirmed successfully',
            'existing' => !$isNewChat,
            'chat_status' => $chat->status // ✅ Include chat status in response
        ]);
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
                ->where('request_type', 'send')
                ->where('request_id', $deliveryRequestId)
                ->where('offer_id', $sendRequestId)
                ->where('status', 'pending')
                ->first();

            if ($response) {
                $response->update(['status' => 'rejected']);

                // Reset delivery request status if no other active responses exist
                $deliveryRequest = DeliveryRequest::find($deliveryRequestId);
                if ($deliveryRequest && $deliveryRequest->status !== 'open') {
                    $hasOtherResponses = Response::where('user_id', $deliveryRequest->user_id)
                        ->where('request_type', 'send')
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
                ->where('request_type', 'delivery')
                ->where('request_id', $sendRequestId)
                ->where('offer_id', $deliveryRequestId)
                ->where('status', 'waiting')
                ->first();

            if ($response) {
                // Mark this response as rejected
                $response->update(['status' => 'rejected']);

                // Get the delivery request to find the deliverer's user ID
                $deliveryRequest = DeliveryRequest::find($deliveryRequestId);

                if ($deliveryRequest) {
                    // Find and reject the original deliverer's response using the correct user_id
                    $delivererResponse = Response::where('user_id', $deliveryRequest->user_id)
                        ->where('request_type', 'send')
                        ->where('offer_id', $sendRequestId)
                        ->whereIn('status', ['responded', 'accepted'])
                        ->first();

                    if ($delivererResponse) {
                        $delivererResponse->update(['status' => 'rejected']);
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
                    $hasOtherResponses = Response::where('request_type', 'delivery')
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
                        ->where('request_type', 'send')
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

        // Find and update the response record
        $response = Response::where('user_id', $user->id)
            ->where('request_type', $offerType)
            ->where('request_id', $requestId)
            ->where('offer_id', $offerId)
            ->where('status', 'waiting')
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Response not found'], 404);
        }

        // Delete the response record for cancellation
        $response->delete();

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
            ->whereIn('status', [Response::STATUS_PENDING, Response::STATUS_WAITING])
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Manual response not found or cannot be cancelled'], 404);
        }

        // Delete the response record for cancellation
        $response->delete();

        // Send notification to request owner that response was cancelled
//        $this->sendTelegramNotification(
//            $response->user_id,
//            $user->name,
//            "Пользователь отменил свой отклик на вашу заявку."
//        );

        return response()->json(['message' => 'Manual response cancelled successfully']);
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

        $token = env('TELEGRAM_BOT_TOKEN');
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
