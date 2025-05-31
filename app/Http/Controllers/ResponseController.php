<?php

namespace App\Http\Controllers;

use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Models\User;
use App\Models\Chat;
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
        $responses = [];

        // Get responses for user's send requests (delivery offers)
        $sendRequests = $user->sendRequests()->where('status', 'open')->get();
        foreach ($sendRequests as $sendRequest) {
            $deliveryOffers = DeliveryRequest::where('status', 'open')
                ->where('from_location', $sendRequest->from_location)
                ->where('to_location', $sendRequest->to_location)
                ->where('user_id', '!=', $user->id)
                ->whereDate('from_date', '<=', $sendRequest->to_date)
                ->whereDate('to_date', '>=', $sendRequest->to_date)
                ->with('user.telegramUser')
                ->get();

            foreach ($deliveryOffers as $offer) {
                $responses[] = [
                    'id' => 'delivery_' . $offer->id . '_send_' . $sendRequest->id,
                    'type' => 'delivery',
                    'request_id' => $sendRequest->id,
                    'offer_id' => $offer->id,
                    'user' => [
                        'id' => $offer->user->id,
                        'name' => $offer->user->name,
                        'image' => $offer->user->telegramUser->image ?? null,
                        'requests_count' => $offer->user->deliveryRequests()->count(),
                    ],
                    'from_location' => $offer->from_location,
                    'to_location' => $offer->to_location,
                    'from_date' => $offer->from_date,
                    'to_date' => $offer->to_date,
                    'price' => $offer->price,
                    'currency' => $offer->currency,
                    'size_type' => $offer->size_type,
                    'description' => $offer->description,
                    'status' => $this->getResponseStatus($sendRequest, $offer),
                ];
            }
        }

        // Get responses for user's delivery requests (send offers)
        $deliveryRequests = $user->deliveryRequests()->where('status', 'open')->get();
        foreach ($deliveryRequests as $deliveryRequest) {
            $sendOffers = SendRequest::where('status', 'open')
                ->where('from_location', $deliveryRequest->from_location)
                ->where('to_location', $deliveryRequest->to_location)
                ->where('user_id', '!=', $user->id)
                ->whereDate('to_date', '>=', $deliveryRequest->from_date)
                ->whereDate('to_date', '<=', $deliveryRequest->to_date)
                ->with('user.telegramUser')
                ->get();

            foreach ($sendOffers as $offer) {
                $responses[] = [
                    'id' => 'send_' . $offer->id . '_delivery_' . $deliveryRequest->id,
                    'type' => 'send',
                    'request_id' => $deliveryRequest->id,
                    'offer_id' => $offer->id,
                    'user' => [
                        'id' => $offer->user->id,
                        'name' => $offer->user->name,
                        'image' => $offer->user->telegramUser->image ?? null,
                        'requests_count' => $offer->user->sendRequests()->count(),
                    ],
                    'from_location' => $offer->from_location,
                    'to_location' => $offer->to_location,
                    'from_date' => null,
                    'to_date' => $offer->to_date,
                    'price' => $offer->price,
                    'currency' => $offer->currency,
                    'size_type' => $offer->size_type,
                    'description' => $offer->description,
                    'status' => $this->getResponseStatus($deliveryRequest, $offer, 'delivery'),
                ];
            }
        }

        // Sort by created date
        usort($responses, function($a, $b) {
            return strtotime($b['to_date'] ?? $b['from_date']) - strtotime($a['to_date'] ?? $a['from_date']);
        });

        return response()->json($responses);
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

        // Create chat
        $chatData = [
            'sender_id' => $user->id,
            'receiver_id' => null,
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

        $chatData['receiver_id'] = $offer->user_id;

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
        $user->decrement('links_balance');

        // Update request statuses
        $offer->update(['status' => 'matched']);
        $userRequest->update(['status' => 'matched']);

        // Send notification to other user
        $this->sendTelegramNotification(
            $offer->user_id,
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

        if ($offerType === 'delivery') {
            $offer = DeliveryRequest::find($offerId);
        } else {
            $offer = SendRequest::find($offerId);
        }

        if (!$offer) {
            return response()->json(['error' => 'Request not found'], 404);
        }

        // Send notification to other user
        $this->sendTelegramNotification(
            $offer->user_id,
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
        // This would be used for responses that are in "waiting" state
        return response()->json(['message' => 'Response cancelled']);
    }

    /**
     * Get response status based on request matching
     */
    private function getResponseStatus($userRequest, $offer, $type = 'send'): string
    {
        // Check if there's an existing chat
        $chatExists = Chat::where(function ($query) use ($userRequest, $offer, $type) {
            if ($type === 'delivery') {
                $query->where('delivery_request_id', $userRequest->id)
                    ->where('send_request_id', $offer->id);
            } else {
                $query->where('send_request_id', $userRequest->id)
                    ->where('delivery_request_id', $offer->id);
            }
        })->exists();

        if ($chatExists) {
            return 'accepted';
        }

        // For now, all new matches are pending
        return 'pending';
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
