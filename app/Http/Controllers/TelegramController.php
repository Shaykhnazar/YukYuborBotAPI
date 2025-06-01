<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Service\Matcher;

class TelegramController extends Controller
{
    public function __construct(
        protected Matcher $matcher,
    ) {}

    public function handle(Request $request)
    {
        $data = $request->all();
        Log::info('Telegram update received', $data);

        if (isset($data['callback_query'])) {
            $callback = $data['callback_query'];
            $chatId = $callback['from']['id'];
            $callbackData = $callback['data'];

            // Handle deliverer responses from Telegram bot
            if (str_starts_with($callbackData, 'delivery_response:')) {
                $this->handleDelivererResponse($chatId, $callbackData);
            }
            // Handle old callback format for backward compatibility
            elseif (str_contains($callbackData, 'request:')) {
                $this->handleLegacyCallback($chatId, $callbackData);
            }
        }

        return response()->noContent();
    }

    /**
     * Handle deliverer response callbacks
     * Format: delivery_response:accept:delivery_id:send_id
     */
    private function handleDelivererResponse($chatId, $callbackData)
    {
        $parts = explode(':', $callbackData);
        if (count($parts) !== 4) {
            $this->sendMessage($chatId, "❌ Неверный формат ответа");
            return;
        }

        $action = $parts[1]; // 'accept' or 'reject'
        $deliveryId = $parts[2];
        $sendId = $parts[3];

        $delivery = DeliveryRequest::find($deliveryId);
        $send = SendRequest::find($sendId);

        if (!$delivery || !$send) {
            $this->sendMessage($chatId, "❌ Заказ не найден или уже обработан");
            return;
        }

        if ($action === 'accept') {
            // Use matcher to create deliverer response
            $this->matcher->createDelivererResponse($send->id, $delivery->id, 'accept');
            $this->sendMessage($chatId, "✅ Отлично! Ваш ответ отправлен отправителю. Ожидайте подтверждения.");
        } else {
            // Handle rejection
            $this->matcher->createDelivererResponse($send->id, $delivery->id, 'reject');
            $this->sendMessage($chatId, "❌ Вы отклонили заказ. Мы найдем вам другие варианты.");
        }
    }

    /**
     * Handle legacy callback format for backward compatibility
     */
    private function handleLegacyCallback($chatId, $callbackData)
    {
        $parts = explode(':', $callbackData);

        if (count($parts) >= 4) {
            $action = $parts[2]; // 'accept' or 'reject'

            if ($action === 'accept') {
                $this->sendMessage($chatId, "✅ Для подтверждения заказа, пожалуйста, используйте приложение.");
            } else {
                $this->sendMessage($chatId, "❌ Заказ отклонен.");
            }
        }
    }

    private function sendMessage($chatId, $text): void
    {
        $token = config('auth.guards.tgwebapp.token');
        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
        ]);

        if ($response->failed()) {
            Log::error('Failed to send Telegram message (callback response)', ['response' => $response->body()]);
        }
    }
}
