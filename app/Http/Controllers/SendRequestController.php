<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Send\CreateSendRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\Chat;
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
        $user = $this->userService->getUserByTelegramId($request);

        // Check active requests limit (combined for both delivery and send)
        $activeDeliveryCount = DeliveryRequest::where('user_id', $user->id)
            ->whereNotIn('status', ['closed'])
            ->count();

        $activeSendCount = SendRequest::where('user_id', $user->id)
            ->whereNotIn('status', ['closed'])
            ->count();

        $totalActiveRequests = $activeDeliveryCount + $activeSendCount;
        $maxActiveRequests = 3; // Total limit for both types

        if ($totalActiveRequests >= $maxActiveRequests) {
            return response()->json([
                'error' => 'Вы можете иметь максимум ' . $maxActiveRequests . ' активных заявок одновременно'
            ], 422);
        }


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
                'user_id' => $user->id,
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

            // Check if request status is completed or matched
            if (in_array($sendRequest->status, ['matched', 'completed'])) {
                return response()->json([
                    'error' => 'Cannot delete completed or matched request'
                ], 409);
            }

            DB::beginTransaction();

            // Delete all related responses where this send request appears
            // as either the main request or as an offer
            Response::where(function($query) use ($id) {
                $query->where(function($subQuery) use ($id) {
                    // Responses where this send request is the main request
                    $subQuery->where('request_type', 'send')
                             ->where('request_id', $id);
                })->orWhere(function($subQuery) use ($id) {
                    // Responses where this send request appears as an offer
                    $subQuery->where('request_type', 'delivery')
                             ->where('offer_id', $id);
                });
            })->delete();

            // Delete any chats related to this send request
            Chat::where('send_request_id', $id)->update(['status' => 'closed']);

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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to delete request'
            ], 500);
        }
    }

    public function close(Request $request, int $id)
    {
        $user = $this->userService->getUserByTelegramId($request);

        $sendRequest = SendRequest::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$sendRequest) {
            return response()->json(['error' => 'Send request not found'], 404);
        }

        if ($sendRequest->status !== 'matched') {
            return response()->json(['error' => 'Can only close matched requests'], 409);
        }

        DB::beginTransaction();

        try {
            // Update the send request status
            $sendRequest->update(['status' => 'closed']);

            // FIX: Also update the matched delivery request
            if ($sendRequest->matched_delivery_id) {
                $deliveryRequest = \App\Models\DeliveryRequest::find($sendRequest->matched_delivery_id);
                if ($deliveryRequest) {
                    $deliveryRequest->update(['status' => 'closed']);
                }
            }

            // FIX: Update all related responses to closed
            Response::where(function($query) use ($id) {
                $query->where(function($subQuery) use ($id) {
                    // Responses where this send request is the main request
                    $subQuery->where('request_type', 'send')
                             ->where('request_id', $id);
                })->orWhere(function($subQuery) use ($id) {
                    // Responses where this send request appears as an offer
                    $subQuery->where('request_type', 'delivery')
                             ->where('offer_id', $id);
                });
            })
            ->whereIn('status', ['accepted', 'waiting'])
            ->update(['status' => 'closed']);

            // FIX: Update chat status to closed
            Chat::where('send_request_id', $id)
                ->update([
                    'send_request_id' => null,
                    'status' => 'closed'
                ]);

            DB::commit();

            Log::info('Send request closed successfully', [
                'request_id' => $id,
                'user_id' => $user->id
            ]);

            return response()->json(['message' => 'Send request closed successfully']);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error closing send request', [
                'request_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to close request'], 500);
        }
    }
}
