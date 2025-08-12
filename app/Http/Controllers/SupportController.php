<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class SupportController extends Controller
{
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'userId' => 'required'
        ]);

        $userId = $request->input('userId');
        $token = config('auth.guards.tgwebapp.token');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Bot token not configured'
            ], 500);
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $userId,
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
