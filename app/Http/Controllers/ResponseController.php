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
     * 1. Send requests that deliverer can respond to (status: pending)
     * 2. Deliverer responses that sender needs to confirm (status: waiting)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        // Get responses from the database where current user is the recipient
        $responses = Response::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'waiting', 'accepted', 'responded'])
            ->with(['responder.telegramUser', 'chat'])
            ->orderByDesc('created_at')
            ->get();

        $formattedResponses = [];

        foreach ($responses as $response) {
            if ($response->request_type === 'send') {
                // Deliverer seeing send requests
                $sendRequest = SendRequest::with(['fromLocation', 'toLocation'])->find($response->offer_id);
                $deliveryRequest = DeliveryRequest::with(['fromLocation', 'toLocation'])->find($response->request_id);

                if (!$sendRequest || !$deliveryRequest) continue;

                // Skip responses where either request is closed
                if ($sendRequest->status === 'closed' || $deliveryRequest->status === 'closed') continue;

                $formattedResponses[] = [
                    'id' => 'send_' . $response->offer_id . '_delivery_' . $response->request_id,
                    'type' => 'send',
                    'request_id' => $response->request_id,
                    'offer_id' => $response->offer_id,
                    'chat_id' => $response->chat_id,
                    'user' => [
                        'id' => $response->responder->id,
                        'name' => $response->responder->name,
                        'image' => $response->responder->telegramUser->image ?? null,
                        'requests_count' => $response->responder->sendRequests()->count(),
                    ],
                    'from_location' => $sendRequest->fromLocation->fullRouteName,
                    'to_location' => $sendRequest->toLocation->fullRouteName,
                    'from_date' => $sendRequest->from_date,
                    'to_date' => $sendRequest->to_date,
                    'price' => $sendRequest->price,
                    'currency' => $sendRequest->currency,
                    'size_type' => $sendRequest->size_type,
                    'description' => $sendRequest->description,
                    'status' => $response->status,
                    'created_at' => $response->created_at,
                    'response_type' => 'can_deliver', // Deliverer can respond to this
                ];

            } elseif ($response->request_type === 'delivery') {
                // Sender seeing deliverer responses
                $sendRequest = SendRequest::with(['fromLocation', 'toLocation'])->find($response->request_id);
                $deliveryRequest = DeliveryRequest::with(['fromLocation', 'toLocation'])->find($response->offer_id);

                if (!$sendRequest || !$deliveryRequest) continue;

                // Skip responses where either request is closed
                if ($sendRequest->status === 'closed' || $deliveryRequest->status === 'closed') continue;

                $formattedResponses[] = [
                    'id' => 'delivery_' . $response->offer_id . '_send_' . $response->request_id,
                    'type' => 'delivery',
                    'request_id' => $response->request_id,
                    'offer_id' => $response->offer_id,
                    'chat_id' => $response->chat_id,
                    'user' => [
                        'id' => $response->responder->id,
                        'name' => $response->responder->name,
                        'image' => $response->responder->telegramUser->image ?? null,
                        'requests_count' => $response->responder->deliveryRequests()->count(),
                    ],
                    'from_location' => $deliveryRequest->fromLocation->fullRouteName,
                    'to_location' => $deliveryRequest->toLocation->fullRouteName,
                    'from_date' => $deliveryRequest->from_date,
                    'to_date' => $deliveryRequest->to_date,
                    'price' => $deliveryRequest->price,
                    'currency' => $deliveryRequest->currency,
                    'size_type' => $deliveryRequest->size_type,
                    'description' => $deliveryRequest->description,
                    'status' => $response->status,
                    'created_at' => $response->created_at,
                    'response_type' => 'deliverer_responded', // Deliverer responded, waiting for sender confirmation
                    // Original send request info
                    'original_request' => [
                        'from_location' => $sendRequest->fromLocation->fullRouteName,
                        'to_location' => $sendRequest->toLocation->fullRouteName,
                        'description' => $sendRequest->description,
                        'price' => $sendRequest->price,
                        'currency' => $sendRequest->currency,
                    ]
                ];
            }
        }

        return response()->json($formattedResponses);
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
