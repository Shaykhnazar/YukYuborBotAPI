<?php

namespace App\Http\Controllers;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Services\Matcher;
use App\Services\TelegramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramControllerNotUsed extends Controller
{
    public function __construct(
        protected Matcher $matcher,
        protected TelegramNotificationService $telegramService,
    ) {}

    public function handle(Request $request)
    {
        $data = $request->all();
        Log::info('Telegram webhook received', $data);

        // Verify webhook token if provided in URL
        $token = $request->route('token');
        if ($token && $token !== env('TELEGRAM_BOT_TOKEN')) {
            Log::warning('Invalid telegram webhook token');
            return response('Unauthorized', 401);
        }

        if (isset($data['callback_query'])) {
            $callback = $data['callback_query'];
            $chatId = $callback['from']['id'];
            $telegramUserId = $callback['from']['id'];
            $callbackData = $callback['data'];

            Log::info('Processing callback', [
                'chat_id' => $chatId,
                'callback_data' => $callbackData,
                'telegram_user_id' => $telegramUserId
            ]);

            // Handle deliverer responses from Telegram bot
            if (str_starts_with($callbackData, 'delivery_response:')) {
                $this->handleDelivererResponse($chatId, $telegramUserId, $callbackData);
            }
            // Handle old/current callback format
            else {
                $this->handleLegacyCallback($chatId, $telegramUserId, $callbackData);
            }

            // Answer the callback query to remove loading state
            $this->telegramService->answerCallbackQuery($callback['id']);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Handle deliverer response callbacks (new format)
     * Format: delivery_response:accept:delivery_id:send_id
     */
    private function handleDelivererResponse($chatId, $telegramUserId, $callbackData)
    {
        $parts = explode(':', $callbackData);
        if (count($parts) !== 4) {
            $this->sendMessage($chatId, "❌ Неверный формат ответа");
            return;
        }

        $action = $parts[1]; // 'accept' or 'reject'
        $deliveryId = (int)$parts[2];
        $sendId = (int)$parts[3];

        $delivery = DeliveryRequest::find($deliveryId);
        $send = SendRequest::find($sendId);

        if (!$delivery || !$send) {
            $this->telegramService->sendMessage($chatId, "❌ Заказ не найден или уже обработан");
            return;
        }

        // Verify that the user clicking is the deliverer
        if ($delivery->user->telegramUser->telegram != $telegramUserId) {
            $this->telegramService->sendMessage($chatId, "❌ Вы не можете отвечать на этот заказ");
            return;
        }

        if ($action === 'accept') {
            // Check if already responded
            $existingResponse = Response::where('user_id', $delivery->user_id)
                ->where('offer_id', $send->id)
                ->where('request_id', $delivery->id)
                ->first();

            if ($existingResponse && $existingResponse->status !== 'pending') {
                $this->telegramService->sendMessage($chatId, "❌ Вы уже отвечали на этот заказ");
                return;
            }

            // Use matcher to create deliverer response
            $this->matcher->createDelivererResponse($send->id, $delivery->id, 'accept');
            $this->telegramService->sendMessage($chatId, "✅ Отлично! Ваш ответ отправлен отправителю. Ожидайте подтверждения в приложении.");

        } else {
            // Handle rejection
            $this->matcher->createDelivererResponse($send->id, $delivery->id, 'reject');
            $this->telegramService->sendMessage($chatId, "❌ Вы отклонили заказ. Мы найдем вам другие варианты.");
        }
    }

    /**
     * Handle legacy/current callback format
     * Your current format from Matcher.php
     */
    private function handleLegacyCallback($chatId, $telegramUserId, $callbackData)
    {
        $parts = explode(':', $callbackData);
        Log::info('Legacy callback parts', $parts);

        if (count($parts) >= 7) {
            // Format: request:delivery:accept:send_id:delivery_id:send_user_id:delivery_user_id
            // or: request:sender:accept:send_id:delivery_id:send_user_id:delivery_user_id

            $requestType = $parts[1]; // 'delivery' or 'sender'
            $action = $parts[2]; // 'accept' or 'reject'
            $sendId = (int)$parts[3];
            $deliveryId = (int)$parts[4];
            $sendUserId = (int)$parts[5];
            $deliveryUserId = (int)$parts[6];

            Log::info('Processing legacy callback', [
                'request_type' => $requestType,
                'action' => $action,
                'send_id' => $sendId,
                'delivery_id' => $deliveryId,
                'telegram_user_id' => $telegramUserId
            ]);

            $delivery = DeliveryRequest::find($deliveryId);
            $send = SendRequest::find($sendId);

            if (!$delivery || !$send) {
                $this->telegramService->sendMessage($chatId, "❌ Заказ не найден или уже обработан");
                return;
            }

            // Verify user permission
            $expectedUserId = ($requestType === 'delivery') ? $deliveryUserId : $sendUserId;
            $user = ($requestType === 'delivery') ? $delivery->user : $send->user;

            if (!$user || !$user->telegramUser || $user->telegramUser->telegram != $telegramUserId) {
                $this->telegramService->sendMessage($chatId, "❌ Вы не можете отвечать на этот заказ");
                return;
            }

            if ($action === 'accept') {
                if ($requestType === 'delivery') {
                    // Deliverer accepting send request
                    $this->matcher->createDelivererResponse($send->id, $delivery->id, 'accept');
                    $this->telegramService->sendMessage($chatId, "✅ Отлично! Ваш ответ отправлен отправителю. Проверьте приложение для дальнейших действий.");
                } else {
                    // Sender accepting delivery (this shouldn't happen via bot usually)
                    $this->telegramService->sendMessage($chatId, "✅ Для завершения подтверждения используйте приложение.");
                }
            } else {
                // Rejection
                if ($requestType === 'delivery') {
                    $this->matcher->createDelivererResponse($send->id, $delivery->id, 'reject');
                }
                $this->telegramService->sendMessage($chatId, "❌ Заказ отклонен.");
            }
        } else {
            Log::warning('Unknown callback format', ['callback_data' => $callbackData]);
            $this->telegramService->sendMessage($chatId, "❌ Неизвестный формат команды");
        }
    }

    /**
     * Send message to Telegram chat (deprecated - use TelegramNotificationService)
     */
    private function sendMessage($chatId, $text): void
    {
        $this->telegramService->sendMessage($chatId, $text);
    }

}
