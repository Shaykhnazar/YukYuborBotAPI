<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;

class TelegramController extends Controller
{
    // App\Http\Controllers\TelegramController.php

    public function handle(Request $request)
    {
        $data = $request->all();
        Log::info('Telegram update received', $data);

        if (isset($data['callback_query'])) {
            $callback = $data['callback_query'];
            $chatId = $callback['from']['id'];
            $payload = json_decode($callback['data'], true);

            if ($payload['action'] === 'accept_order') {
                $this->handleAcceptOrder($chatId, $payload);
            } elseif ($payload['action'] === 'reject_order') {
                $this->handleRejectOrder($chatId, $payload);
            }
        }

        return response()->noContent();
    }

    private function handleAcceptOrder($chatId, $payload)
    {
        $delivery = DeliveryRequest::find($payload['delivery_id']);
        $send = SendRequest::find($payload['send_id']);

        if ($delivery && $send) {
            $delivery->status = 'matched';
            $delivery->matched_send_id = $send->id;
            $delivery->save();

            $send->status = 'matched';
            $send->matched_delivery_id = $delivery->id;
            $send->save();

            // Уведомляем обоих пользователей
            $this->sendMessage($chatId, "✅ Вы приняли заказ! Свяжитесь с отправителем для деталей.");

            $senderMessage = "🎉 Ваш заказ №{$send->id} принят! Вот контакт получателя:";
            $this->sendMessage($payload['send_user_id'], $senderMessage);
        } else {
            $this->sendMessage($chatId, "❌ Заказ уже был обработан другим пользователем");
        }
    }

    private function handleRejectOrder($chatId, $payload)
    {
        $this->sendMessage($chatId, "Вы отклонили заказ. Мы найдем вам другие варианты.");
    }

    private function sendMessage($chatId, $text): void
    {

        $token = env('TELEGRAM_BOT_TOKEN');
        $response = \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
        ]);

        if ($response->failed()) {
            Log::error('Failed to send Telegram message (callback response)', ['response' => $response->body()]);
        }
    }
}
