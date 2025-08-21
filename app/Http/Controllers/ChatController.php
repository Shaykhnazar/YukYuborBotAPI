<?php

namespace App\Http\Controllers;

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Chat\ChatCreateRequest;
use App\Http\Requests\SendMessageRequest;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\TelegramUserService;
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

        // Get only one chat per user pair, prioritizing active chats
        $chats = Chat::where('sender_id', $user->id)
            ->orWhere('receiver_id', $user->id)
            ->with([
                'sender.telegramUser',
                'receiver.telegramUser',
                'latestMessage',
                'sendRequest.fromLocation',
                'sendRequest.toLocation',
                'deliveryRequest.fromLocation',
                'deliveryRequest.toLocation',
                'response'
            ])
            ->get()
            ->unique(function ($chat) use ($user) {
                // Create a unique key based on the other user's ID
                return $chat->sender_id === $user->id ? $chat->receiver_id : $chat->sender_id;
            })
            ->sortByDesc('updated_at')
            ->values();

        $chatsData = $chats->map(function ($chat) use ($user) {
            $otherUser = $chat->sender_id === $user->id ? $chat->receiver : $chat->sender;
            $requestInfo = $this->getRequestInfo($chat);
            $isCompleted = $this->isChatCompleted($chat);

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
                'unread_count' => $isCompleted ? 0 : $chat->unreadMessagesCount($user->id),
                'request_info' => $requestInfo,
                'status' => $chat->status, // ✅ Use actual status, don't override
                'is_completed' => $isCompleted,
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
                'sendRequest.fromLocation',
                'sendRequest.toLocation',
                'deliveryRequest.fromLocation',
                'deliveryRequest.toLocation',
                'response'
            ])
            ->firstOrFail();

//        Log::info('📱 Loading chat', [
//            'chat_id' => $chatId,
//            'user_id' => $user->id,
//            'chat_status' => $chat->status
//        ]);

        $isCompleted = $this->isChatCompleted($chat);

        // ✅ FIXED: Don't auto-update chat status unless explicitly needed
        // Only mark messages as read for active chats
        $unreadMessageIds = [];
        if (!$isCompleted) {
            // Get unread message IDs before marking as read
            $unreadMessageIds = $chat->messages()
                ->where('sender_id', '!=', $user->id)
                ->where('is_read', false)
                ->pluck('id')
                ->toArray();

            // Mark messages as read
            if (!empty($unreadMessageIds)) {
                $chat->messages()
                    ->whereIn('id', $unreadMessageIds)
                    ->update(['is_read' => true, 'updated_at' => now()]);

                // Broadcast read receipt
                broadcast(new MessageRead($user, $chat->id, $unreadMessageIds));
            }
        }

        // ✅ REMOVED: Don't auto-update chat status to completed
        // Let users explicitly close chats when they're done

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
                    'updated_at' => $message->updated_at,
                ];
            }),
            'request_info' => $this->getRequestInfo($chat),
            'status' => $chat->status, // ✅ Use actual chat status, don't override
            'is_completed' => $isCompleted,
            'unread_marked' => count($unreadMessageIds),
        ];

//        Log::info('📱 Chat loaded successfully', [
//            'chat_id' => $chatId,
//            'status' => $chat->status,
//            'is_completed' => $isCompleted,
//            'message_count' => count($chat->messages)
//        ]);

        return response()->json($chatData);
    }

    /**
     * Send a message in chat
     */
    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        $chat = Chat::where('id', $request->chat_id)
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->with(['sendRequest', 'deliveryRequest'])
            ->firstOrFail();

        // Check if chat is active and not completed
        if ($chat->status !== 'active') {
            return response()->json(['error' => 'Chat is not active'], 403);
        }

        // Check if related requests are completed
        if ($this->isChatCompleted($chat)) {
            return response()->json(['error' => 'Cannot send messages to completed chat'], 403);
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

        // Broadcast the event - this triggers real-time updates
        broadcast(new MessageSent($user, $message, $chat->id));

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

        // First check for any existing chat between these two users
        $existingChat = Chat::where(function ($query) use ($user, $request) {
            $query->where(function ($subQuery) use ($user, $request) {
                $subQuery->where('sender_id', $user->id)
                    ->where('receiver_id', $request->other_user_id);
            })->orWhere(function ($subQuery) use ($user, $request) {
                $subQuery->where('sender_id', $request->other_user_id)
                    ->where('receiver_id', $user->id);
            });
        })->first();

        if ($existingChat) {
            // Reopen the chat if it's closed/completed
            if (in_array($existingChat->status, ['closed', 'completed'])) {
                $existingChat->update(['status' => 'active']);
            }

            // Update request references if needed
            if ($request->request_type === 'send' && !$existingChat->send_request_id) {
                $existingChat->update(['send_request_id' => $request->request_id]);
            } elseif ($request->request_type === 'delivery' && !$existingChat->delivery_request_id) {
                $existingChat->update(['delivery_request_id' => $request->request_id]);
            }

            return response()->json([
                'chat_id' => $existingChat->id,
                'message' => 'Chat reopened successfully',
                'existing' => true
            ]);
        }

        // Check if user has enough links for new chat creation
        if ($user->links_balance <= 0) {
            return response()->json(['error' => 'Insufficient links balance'], 403);
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

        // Deduct one link from user's balance
        // TODO: apply deduct logic after payment integration is done
        // $user->decrement('links_balance');

        return response()->json([
            'chat_id' => $chat->id,
            'message' => 'Chat created successfully',
            'existing' => false
        ]);
    }

    /**
     * Mark messages as read and broadcast read receipt
     */
    public function markAsRead(Request $request, int $chatId): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        $chat = Chat::where('id', $chatId)
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->with(['sendRequest', 'deliveryRequest'])
            ->firstOrFail();

        // Don't mark messages as read for completed chats
        if ($this->isChatCompleted($chat)) {
            return response()->json([
                'marked_as_read' => 0,
                'message_ids' => [],
                'reason' => 'Chat is completed'
            ]);
        }

        // Get unread messages from other users
        $unreadMessages = $chat->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->get();

        if ($unreadMessages->isNotEmpty()) {
            $messageIds = $unreadMessages->pluck('id')->toArray();

            // Mark messages as read
            $chat->messages()
                ->whereIn('id', $messageIds)
                ->update(['is_read' => true, 'updated_at' => now()]);

            // Broadcast read receipt to other user
            broadcast(new MessageRead($user, $chat->id, $messageIds));

//            Log::info('Messages marked as read', [
//                'user_id' => $user->id,
//                'chat_id' => $chat->id,
//                'message_count' => count($messageIds)
//            ]);
        }

        return response()->json([
            'marked_as_read' => count($unreadMessages),
            'message_ids' => $unreadMessages->pluck('id')
        ]);
    }

    /**
     * Handle typing indicator
     */
    public function setTyping(Request $request): JsonResponse
    {
        $request->validate([
            'chat_id' => 'required|integer|exists:chats,id',
            'is_typing' => 'required|boolean',
        ]);

        $user = $this->tgService->getUserByTelegramId($request);
        $chatId = $request->input('chat_id');

        // Verify user can access this chat
        $chat = Chat::where('id', $chatId)
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->with(['sendRequest', 'deliveryRequest'])
            ->firstOrFail();

        // Don't send typing indicators for completed chats
        if ($this->isChatCompleted($chat)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot send typing indicators for completed chat',
                'is_typing' => false
            ]);
        }

        // Broadcast typing status
        broadcast(new UserTyping($user, $chatId, $request->boolean('is_typing')));

//        Log::info('Typing status updated', [
//            'user_id' => $user->id,
//            'chat_id' => $chatId,
//            'is_typing' => $request->boolean('is_typing')
//        ]);

        return response()->json([
            'status' => 'success',
            'is_typing' => $request->boolean('is_typing')
        ]);
    }

    /**
     * Get online users for a specific chat
     */
    public function getOnlineUsers(Request $request, int $chatId): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        // Verify user can access this chat
        $chat = Chat::where('id', $chatId)
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->firstOrFail();

        // For now, we'll return basic info
        // In a real implementation, you'd track online status in Redis/cache
        $otherUser = $chat->sender_id === $user->id ? $chat->receiver : $chat->sender;

        return response()->json([
            'chat_id' => $chatId,
            'other_user' => [
                'id' => $otherUser->id,
                'name' => $otherUser->name,
                'last_seen' => $otherUser->updated_at, // Basic implementation
            ],
            'online_users' => [] // This would be populated by presence channel data
        ]);
    }

    /**
     * Complete/close a chat when request is finished
     */
    public function completeChat(Request $request, int $chatId): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500'
        ]);

        $user = $this->tgService->getUserByTelegramId($request);

        $chat = Chat::where('id', $chatId)
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->with(['sendRequest', 'deliveryRequest'])
            ->firstOrFail();

        // Update chat status to completed
        $chat->update(['status' => 'completed']);

        // Update related request statuses to completed
        if ($chat->sendRequest && in_array($chat->sendRequest->status, ['matched', 'matched_manually', 'active'])) {
            $chat->sendRequest->update(['status' => 'completed']);
        }

        if ($chat->deliveryRequest && in_array($chat->deliveryRequest->status, ['matched', 'matched_manually', 'active'])) {
            $chat->deliveryRequest->update(['status' => 'completed']);
        }

        // Add system message about completion
        $systemMessage = new ChatMessage([
            'chat_id' => $chatId,
            'sender_id' => $user->id,
            'message' => "Заявка завершена пользователем {$user->name}",
            'message_type' => 'system',
            'is_read' => true,
        ]);
        $systemMessage->save();

        // Send notification to other user
        $otherUserId = $chat->sender_id === $user->id ? $chat->receiver_id : $chat->sender_id;
        $this->sendTelegramNotification(
            $otherUserId,
            'PostLink',
            "Заявка завершена пользователем {$user->name}. Спасибо за использование PostLink!"
        );

//        Log::info('Chat completed', [
//            'chat_id' => $chatId,
//            'completed_by' => $user->id,
//            'reason' => $request->input('reason')
//        ]);

        return response()->json([
            'message' => 'Chat completed successfully',
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $user->name
        ]);
    }

    /**
     * Check if chat should be considered completed
     */
    private function isChatCompleted(Chat $chat): bool
    {
//        Log::info('🔍 Checking chat completion status', [
//            'chat_id' => $chat->id,
//            'chat_status' => $chat->status,
//            'has_send_request' => !!$chat->sendRequest,
//            'has_delivery_request' => !!$chat->deliveryRequest,
//            'send_request_status' => $chat->sendRequest?->status,
//            'delivery_request_status' => $chat->deliveryRequest?->status
//        ]);

        // ✅ FIXED: Chat is only completed if explicitly marked as closed/completed
        if (in_array($chat->status, ['closed', 'completed'])) {
//            Log::info('✅ Chat marked as closed/completed', ['chat_id' => $chat->id]);
            return true;
        }

        // ✅ FIXED: Don't auto-complete based on request status alone
        // Only consider requests that are explicitly finished
        $sendCompleted = false;
        $deliveryCompleted = false;

        if ($chat->sendRequest) {
            // ✅ FIXED: Only consider 'completed' or 'closed', not 'matched'
            $sendCompleted = in_array($chat->sendRequest->status, ['completed', 'closed']);
        } else {
            // ✅ FIXED: If no send request, don't assume completed
            $sendCompleted = false;
        }

        if ($chat->deliveryRequest) {
            // ✅ FIXED: Only consider 'completed' or 'closed', not 'matched'
            $deliveryCompleted = in_array($chat->deliveryRequest->status, ['completed', 'closed']);
        } else {
            // ✅ FIXED: If no delivery request, don't assume completed
            $deliveryCompleted = false;
        }

        // ✅ FIXED: Chat is completed only if:
        // 1. Chat status is explicitly closed/completed, OR
        // 2. BOTH requests exist AND both are completed/closed
        $bothRequestsCompleted = ($chat->sendRequest && $chat->deliveryRequest) &&
                                ($sendCompleted && $deliveryCompleted);

        $isCompleted = /*$bothRequestsCompleted*/ false;

//        Log::info('🔍 Chat completion analysis', [
//            'chat_id' => $chat->id,
//            'send_completed' => $sendCompleted,
//            'delivery_completed' => $deliveryCompleted,
//            'both_requests_completed' => $bothRequestsCompleted,
//            'final_is_completed' => $isCompleted
//        ]);

        return $isCompleted;
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
                'user_id' => $chat->sendRequest->user_id,
                'from_location' => $chat->sendRequest->fromLocation->fullRouteName,
                'to_location' => $chat->sendRequest->toLocation->fullRouteName,
                'description' => $chat->sendRequest->description,
                'price' => $chat->sendRequest->price,
                'currency' => $chat->sendRequest->currency,
                'to_date' => $chat->sendRequest->to_date,
                'status' => $chat->sendRequest->status,
                'response_type' => $chat->response ? $chat->response->response_type : 'unknown',
            ];
        }

        if ($chat->deliveryRequest) {
            return [
                'type' => 'delivery',
                'id' => $chat->deliveryRequest->id,
                'user_id' => $chat->deliveryRequest->user_id,
                'from_location' => $chat->deliveryRequest->fromLocation->fullRouteName,
                'to_location' => $chat->deliveryRequest->toLocation->fullRouteName,
                'description' => $chat->deliveryRequest->description,
                'price' => $chat->deliveryRequest->price,
                'currency' => $chat->deliveryRequest->currency,
                'from_date' => $chat->deliveryRequest->from_date,
                'to_date' => $chat->deliveryRequest->to_date,
                'status' => $chat->deliveryRequest->status,
                'response_type' => $chat->response ? $chat->response->response_type : 'unknown',
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
        $notificationText = "🛎 *Новое сообщение от {$senderName}*\nОткройте приложение, чтобы ответить 👇🏻";

        $token = config('auth.guards.tgwebapp.token');
        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $telegramId,
            'text' => $notificationText,
            'parse_mode' => 'Markdown',
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
