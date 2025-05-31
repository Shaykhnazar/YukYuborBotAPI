<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Chat\ChatCreateRequest;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Service\TelegramUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    ) {}

    /**
     * Get all chats for current user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        $chats = Chat::where('sender_id', $user->id)
            ->orWhere('receiver_id', $user->id)
            ->with([
                'sender.telegramUser',
                'receiver.telegramUser',
                'latestMessage',
                'sendRequest',
                'deliveryRequest'
            ])
            ->orderByDesc('updated_at')
            ->get();

        $chatsData = $chats->map(function ($chat) use ($user) {
            $otherUser = $chat->sender_id === $user->id ? $chat->receiver : $chat->sender;

            return [
                'id' => $chat->id,
                'other_user' => [
                    'id' => $otherUser->id,
                    'name' => $otherUser->name,
                    'username' => $otherUser->telegramUser->username ?? null,
                    'image' => $otherUser->telegramUser->image ?? null,
                ],
                'latest_message' => $chat->latestMessage ? [
                    'message' => $chat->latestMessage->message,
                    'created_at' => $chat->latestMessage->created_at,
                    'is_my_message' => $chat->latestMessage->sender_id === $user->id,
                ] : null,
                'unread_count' => $chat->unreadMessagesCount($user->id),
                'request_info' => $this->getRequestInfo($chat),
                'status' => $chat->status,
                'created_at' => $chat->created_at,
                'updated_at' => $chat->updated_at,
            ];
        });

        return response()->json($chatsData);
    }

    /**
     * Get specific chat with messages
     */
    public function show(Request $request, int $chatId): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        $chat = Chat::where('id', $chatId)
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->with([
                'sender.telegramUser',
                'receiver.telegramUser',
                'messages.sender.telegramUser',
                'sendRequest',
                'deliveryRequest'
            ])
            ->firstOrFail();

        // Mark messages as read
        $chat->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $otherUser = $chat->sender_id === $user->id ? $chat->receiver : $chat->sender;

        $chatData = [
            'id' => $chat->id,
            'other_user' => [
                'id' => $otherUser->id,
                'name' => $otherUser->name,
                'username' => $otherUser->telegramUser->username ?? null,
                'image' => $otherUser->telegramUser->image ?? null,
            ],
            'messages' => $chat->messages->map(function ($message) use ($user) {
                return [
                    'id' => $message->id,
                    'message' => $message->message,
                    'sender_id' => $message->sender_id,
                    'is_my_message' => $message->sender_id === $user->id,
                    'sender_name' => $message->sender->name,
                    'message_type' => $message->message_type,
                    'is_read' => $message->is_read,
                    'created_at' => $message->created_at,
                ];
            }),
            'request_info' => $this->getRequestInfo($chat),
            'status' => $chat->status,
        ];

        return response()->json($chatData);
    }

    /**
     * Send a message in chat
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'chat_id' => 'required|integer|exists:chats,id',
            'message' => 'required|string|max:1000',
            'message_type' => 'nullable|string|in:text,image,file'
        ]);

        $user = $this->tgService->getUserByTelegramId($request);

        $chat = Chat::where('id', $request->chat_id)
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->firstOrFail();

        // Check if chat is active
        if ($chat->status !== 'active') {
            return response()->json(['error' => 'Chat is not active'], 403);
        }

        $message = new ChatMessage([
            'chat_id' => $chat->id,
            'sender_id' => $user->id,
            'message' => $request->message,
            'message_type' => $request->message_type ?? 'text',
        ]);
        $message->save();

        // Update chat timestamp
        $chat->touch();

        // Send notification to the other user via Telegram bot
        $receiverId = $chat->sender_id === $user->id ? $chat->receiver_id : $chat->sender_id;
        $this->sendTelegramNotification($receiverId, $user->name, $request->message);

        return response()->json([
            'id' => $message->id,
            'message' => $message->message,
            'sender_id' => $message->sender_id,
            'is_my_message' => true,
            'sender_name' => $user->name,
            'message_type' => $message->message_type,
            'is_read' => $message->is_read,
            'created_at' => $message->created_at,
        ]);
    }

    /**
     * Create new chat (start dialog with matched user)
     */
    public function createChat(ChatCreateRequest $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        // Check if user has enough links
        if ($user->links_balance <= 0) {
            return response()->json(['error' => 'Insufficient links balance'], 403);
        }

        // Check if chat already exists
        $existingChat = Chat::where(function ($query) use ($request) {
            if ($request->request_type === 'send') {
                $query->where('send_request_id', $request->request_id);
            } else {
                $query->where('delivery_request_id', $request->request_id);
            }
        })
            ->where(function ($query) use ($user, $request) {
                $query->where('sender_id', $user->id)
                    ->where('receiver_id', $request->other_user_id)
                    ->orWhere('sender_id', $request->other_user_id)
                    ->where('receiver_id', $user->id);
            })
            ->first();

        if ($existingChat) {
            return response()->json(['error' => 'Chat already exists', 'chat_id' => $existingChat->id], 409);
        }

        // Create new chat
        $chatData = [
            'sender_id' => $user->id,
            'receiver_id' => $request->other_user_id,
            'status' => 'active',
        ];

        if ($request->request_type === 'send') {
            $chatData['send_request_id'] = $request->request_id;
        } else {
            $chatData['delivery_request_id'] = $request->request_id;
        }

        $chat = new Chat($chatData);
        $chat->save();

        // Deduct one link from user's balance TODO:: apply deduct logic after payment integration is done
//        $user->decrement('links_balance');

        return response()->json([
            'chat_id' => $chat->id,
            'message' => 'Chat created successfully'
        ]);
    }

    /**
     * Get request information for chat context
     */
    private function getRequestInfo(Chat $chat): array
    {
        if ($chat->sendRequest) {
            return [
                'type' => 'send',
                'id' => $chat->sendRequest->id,
                'from_location' => $chat->sendRequest->from_location,
                'to_location' => $chat->sendRequest->to_location,
                'description' => $chat->sendRequest->description,
                'price' => $chat->sendRequest->price,
                'currency' => $chat->sendRequest->currency,
                'to_date' => $chat->sendRequest->to_date,
            ];
        }

        if ($chat->deliveryRequest) {
            return [
                'type' => 'delivery',
                'id' => $chat->deliveryRequest->id,
                'from_location' => $chat->deliveryRequest->from_location,
                'to_location' => $chat->deliveryRequest->to_location,
                'description' => $chat->deliveryRequest->description,
                'price' => $chat->deliveryRequest->price,
                'currency' => $chat->deliveryRequest->currency,
                'from_date' => $chat->deliveryRequest->from_date,
                'to_date' => $chat->deliveryRequest->to_date,
            ];
        }

        return [];
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
        $notificationText = "💬 Новое сообщение от {$senderName}:\n\n{$message}";

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
