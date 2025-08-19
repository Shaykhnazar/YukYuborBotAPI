<?php

namespace App\Services\UserRequest;

use Illuminate\Support\Collection;

class UserRequestFormatterService
{
    public function formatRequestCollection(Collection $requests): Collection
    {
        return $requests->map(function ($request) {
            return $this->formatRequest($request);
        });
    }

    public function formatRequest($request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->type,
            'user_id' => $request->user_id,
            'from_location' => $request->fromLocation->fullRouteName ?? $request->from_location,
            'to_location' => $request->toLocation->fullRouteName ?? $request->to_location,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
            'price' => $request->price,
            'currency' => $request->currency,
            'size_type' => $request->size_type,
            'description' => $request->description,
            'status' => $request->status,
            'created_at' => $request->created_at,
            'updated_at' => $request->updated_at,
            
            // Response-related data (if available)
            'response_id' => $request->response_id ?? null,
            'chat_id' => $request->chat_id ?? null,
            'response_status' => $request->response_status ?? null,
            'response_type' => $request->response_type ?? null,
            'user_role' => $request->user_role ?? null,
            'user_status' => $request->user_status ?? null,
            'has_reviewed' => $request->has_reviewed ?? false,
            
            // User data
            'user' => $this->formatUser($request->user),
            'responder_user' => $request->responder_user ? $this->formatResponder($request->responder_user) : null,
        ];
    }

    private function formatUser($user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'image' => $user->telegramUser->image ?? null,
            'telegram' => $user->telegramUser->telegram ?? null,
        ];
    }

    private function formatResponder($responder): array
    {
        return [
            'id' => $responder->id,
            'name' => $responder->name,
            'image' => $responder->telegramUser->image ?? null,
            'telegram' => $responder->telegramUser->telegram ?? null,
            'closed_send_requests_count' => $responder->closed_send_requests_count ?? 0,
            'closed_delivery_requests_count' => $responder->closed_delivery_requests_count ?? 0,
        ];
    }
}