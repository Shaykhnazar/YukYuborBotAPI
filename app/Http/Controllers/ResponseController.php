<?php

namespace App\Http\Controllers;

use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Models\User;
use App\Models\Chat;
use App\Models\Response;
use App\Service\TelegramUserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResponseController extends Controller
{
    public function __construct(
        protected TelegramUserService $tgService,
    ) {}

    /**
     * Get all responses for current user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        // Get responses from the database where current user is the recipient
        $responses = Response::where('user_id', $user->id)
            ->where('status', 'pending') // Only show pending responses
            ->with(['responder.telegramUser'])
            ->orderByDesc('created_at')
            ->get();

        $formattedResponses = [];

        foreach ($responses as $response) {
            // Get the offer details based on request type
            if ($response->request_type === 'send') {
                $offer = DeliveryRequest::find($response->offer_id);
                $userRequest = SendRequest::find($response->request_id);
            } else {
                $offer = SendRequest::find($response->offer_id);
                $userRequest = DeliveryRequest::find($response->request_id);
            }

            if (!$offer || !$userRequest) {
                continue; // Skip if request/offer not found
            }

            $formattedResponses[] = [
                'id' => ($response->request_type === 'send' ? 'delivery' : 'send') . '_' . $response->offer_id . '_' .
                       ($response->request_type === 'send' ? 'send' : 'delivery') . '_' . $response->request_id,
                'type' => $response->request_type,
                'request_id' => $response->request_id,
                'offer_id' => $response->offer_id,
                'user' => [
                    'id' => $response->responder->id,
                    'name' => $response->responder->name,
                    'image' => $response->responder->telegramUser->image ?? null,
                    'requests_count' => $response->request_type === 'send'
                        ? $response->responder->deliveryRequests()->count()
                        : $response->responder->sendRequests()->count(),
                ],
                'from_location' => $offer->from_location,
                'to_location' => $offer->to_location,
                'from_date' => $offer->from_date ?? null,
                'to_date' => $offer->to_date,
                'price' => $offer->price,
                'currency' => $offer->currency,
                'size_type' => $offer->size_type,
                'description' => $offer->description,
                'status' => $response->status,
                'created_at' => $response->created_at,
            ];
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

        $offerType = $parts[0]; // 'delivery' or 'send'
        $offerId = $parts[1];
        $requestType = $parts[2]; // 'send' or 'delivery'
        $requestId = $parts[3];

        // Find the response record
        $response = Response::where('user_id', $user->id)
            ->where('request_type', $offerType)
            ->where('request_id', $requestId)
            ->where('offer_id', $offerId)
            ->where('status', 'pending')
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Response not found'], 404);
        }

        // Create chat
        $chatData = [
            'sender_id' => $user->id,
            'receiver_id' => $response->responder_id,
            'status' => 'active',
        ];

        if ($offerType === 'delivery') {
            $offer = DeliveryRequest::find($offerId);
            $userRequest = SendRequest::find($requestId);
            $chatData['delivery_request_id'] = $offerId;
            $chatData['send_request_id'] = $requestId;
        } else {
            $offer = SendRequest::find($offerId);
            $userRequest = DeliveryRequest::find($requestId);
            $chatData['send_request_id'] = $offerId;
            $chatData['delivery_request_id'] = $requestId;
        }

        if (!$offer || !$userRequest) {
            return response()->json(['error' => 'Request not found'], 404);
        }

        // Check if chat already exists
        $existingChat = Chat::where(function ($query) use ($chatData) {
            $query->where('sender_id', $chatData['sender_id'])
                ->where('receiver_id', $chatData['receiver_id']);
        })
            ->where(function ($query) use ($chatData) {
                if (isset($chatData['send_request_id'])) {
                    $query->where('send_request_id', $chatData['send_request_id']);
                }
                if (isset($chatData['delivery_request_id'])) {
                    $query->where('delivery_request_id', $chatData['delivery_request_id']);
                }
            })
            ->first();

        if ($existingChat) {
            return response()->json(['error' => 'Chat already exists', 'chat_id' => $existingChat->id], 409);
        }

        // Create chat and deduct link
        $chat = Chat::create($chatData);
//        $user->decrement('links_balance'); // TODO: After MVP version deduction will be actualized

        // Update response status to accepted
        $response->update([
            'status' => 'accepted',
            'chat_id' => $chat->id
        ]);

        // Update request statuses
        $offer->update(['status' => 'matched']);
        $userRequest->update(['status' => 'matched']);

        // Reject all other pending responses for the same request
        Response::where('user_id', $user->id)
            ->where('request_type', $offerType)
            ->where('request_id', $requestId)
            ->where('status', 'pending')
            ->where('id', '!=', $response->id)
            ->update(['status' => 'rejected']);

        // Send notification to other user
        $this->sendTelegramNotification(
            $response->responder_id,
            $user->name,
            "Ваше предложение принято! Начните общение в чате."
        );

        return response()->json([
            'chat_id' => $chat->id,
            'message' => 'Response accepted successfully'
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

        $offerType = $parts[0];
        $offerId = $parts[1];
        $requestType = $parts[2];
        $requestId = $parts[3];

        // Find and update the response record
        $response = Response::where('user_id', $user->id)
            ->where('request_type', $offerType)
            ->where('request_id', $requestId)
            ->where('offer_id', $offerId)
            ->where('status', 'pending')
            ->first();

        if (!$response) {
            return response()->json(['error' => 'Response not found'], 404);
        }

        // Update response status to rejected
        $response->update(['status' => 'rejected']);

        // Send notification to other user
        $this->sendTelegramNotification(
            $response->responder_id,
            $user->name,
            "Ваше предложение было отклонено."
        );

        return response()->json(['message' => 'Response rejected']);
    }

    /**
     * Cancel a response (for waiting responses)
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
