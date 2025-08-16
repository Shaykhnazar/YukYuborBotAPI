<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Services\TelegramUserService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SupportController extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    )
    {
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);

        if (!$user || !$user->telegramUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $telegramId = $user->telegramUser->telegram;
        $token = config('auth.guards.tgwebapp.token');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Bot token not configured'
            ], 500);
        }

        try {
            // Send the support trigger message
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $telegramId,
                'text' => '/support',
                'parse_mode' => 'HTML'
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if ($data['ok']) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Support request initiated successfully'
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Telegram API error: '.($data['description'] ?? 'Unknown error')
                ], 400);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message to Telegram'
            ], 500);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error sending support message: '.$e->getMessage()
            ], 500);
        }
    }
}
