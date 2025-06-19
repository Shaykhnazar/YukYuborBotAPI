<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Send\CreateSendRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Service\Matcher;
use App\Service\TelegramUserService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendRequestController extends BaseController
{
    public function __construct(
        protected TelegramUserService $userService,
        protected Matcher $matcher
    )
    {
    }

    public function create(CreateSendRequest $request)
    {
        $dto = $request->getDTO();
        $sendReq = new SendRequest(
            [
                'from_location' => $dto->fromLoc,
                'to_location' => $dto->toLoc,
                'description' => $dto->desc ?? null,
                'from_date' => CarbonImmutable::now()->toDateString(),
                'to_date' => $dto->toDate->toDateString(),
                'price' => $dto->price ?? null,
                'currency' => $dto->currency ?? null,
                'user_id' => $this->userService->getUserByTelegramId($request)->id,
                'status' => 'open',
            ]
        );
        $sendReq->save();
        $this->matcher->matchSendRequest($sendReq);

        return response()->json($sendReq);
    }

    public function delete(Request $request, int $id)
    {
        try {
            $user = $this->userService->getUserByTelegramId($request);

            $sendRequest = SendRequest::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$sendRequest) {
                return response()->json(['error' => 'Request not found'], 404);
            }

            // Check if request can be deleted (no active responses or matched status)
            if ($sendRequest->status === 'matched') {
                return response()->json([
                    'error' => 'Cannot delete request with active collaboration'
                ], 409);
            }

            // Check for active responses
            $hasActiveResponses = Response::where('request_type', 'send')
                ->where('request_id', $id)
                ->where('status', 'pending')
                ->exists();

            if ($hasActiveResponses) {
                return response()->json([
                    'error' => 'Cannot delete request with pending responses'
                ], 409);
            }

            DB::beginTransaction();

            // Delete related responses
            Response::where('request_type', 'send')
                ->where('request_id', $id)
                ->delete();

            // Delete the request
            $sendRequest->delete();

            DB::commit();

            Log::info('Send request deleted successfully', [
                'request_id' => $id,
                'user_id' => $user->id
            ]);

            return response()->json(['message' => 'Request deleted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error deleting send request', [
                'request_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to delete request'
            ], 500);
        }
    }

}
