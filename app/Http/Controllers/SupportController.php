<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Services\TelegramUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SupportController extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    ) {}

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
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $telegramId,
                'text' => 'служба поддержки'
            ]);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Support message sent successfully'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message'
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error sending support message: ' . $e->getMessage()
            ], 500);
        }
    }
}
