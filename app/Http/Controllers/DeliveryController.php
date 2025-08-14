<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Delivery\CreateDeliveryRequest;
use App\Models\Chat;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Services\Matcher;
use App\Services\TelegramUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliveryController extends BaseController
{
    public function __construct(
        protected TelegramUserService $userService,
        protected Matcher $matcher
    )
    {
    }

    public function create(CreateDeliveryRequest $request)
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
                'error' => 'Удалите либо завершите одну из активных заявок, чтобы создать новую.',
                'errorTitle' => 'Превышен лимит заявок'
            ], 422);
        }

        $dto = $request->getDTO();
        $deliveryReq = new DeliveryRequest(
            [
                'from_location_id' => $dto->fromLocId,
                'to_location_id' => $dto->toLocId,
                'description' => $dto->desc ?? null,
                'from_date' => $dto->fromDate->toDateString(),
                'to_date' => $dto->toDate->toDateString(),
                'price' => $dto->price ?? null,
                'currency' => $dto->currency ?? null,
                'user_id' => $user->id,
                'status' => 'open',
            ]
        );
        $deliveryReq->save();
        $this->matcher->matchDeliveryRequest($deliveryReq);

        return response()->json($deliveryReq);
    }

    public function delete(Request $request, int $id)
    {
        try {
            $user = $this->userService->getUserByTelegramId($request);

            $deliveryRequest = DeliveryRequest::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$deliveryRequest) {
                return response()->json(['error' => 'Request not found'], 404);
            }

            // Check if request status is completed or matched
            if (in_array($deliveryRequest->status, ['matched', 'matched_manually', 'completed'])) {
                return response()->json([
                    'error' => 'Cannot delete completed or matched request'
                ], 409);
            }

            DB::beginTransaction();

            // Delete all related responses where this delivery request appears
            // as either the main request or as an offer
            Response::where(function($query) use ($id) {
                $query->where(function($subQuery) use ($id) {
                    // Responses where this delivery request is the main request
                    $subQuery->where('request_type', 'delivery')
                             ->where('request_id', $id);
                })->orWhere(function($subQuery) use ($id) {
                    // Responses where this delivery request appears as an offer
                    $subQuery->where('request_type', 'send')
                             ->where('offer_id', $id);
                });
            })->delete();

            // Delete any chats related to this delivery request
            Chat::where('delivery_request_id', $id)->update(['status' => 'closed']);

            // Delete the request
            $deliveryRequest->delete();

            DB::commit();

//            Log::info('Delivery request deleted successfully', [
//                'request_id' => $id,
//                'user_id' => $user->id
//            ]);

            return response()->json(['message' => 'Request deleted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error deleting delivery request', [
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

        $deliveryRequest = DeliveryRequest::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$deliveryRequest) {
            return response()->json(['error' => 'Delivery request not found'], 404);
        }

        if (!in_array($deliveryRequest->status, ['matched', 'matched_manually'])) {
            return response()->json(['error' => 'Can only close matched requests'], 409);
        }

        DB::beginTransaction();

        try {
            // Update the delivery request status
            $deliveryRequest->update(['status' => 'closed']);

            // FIX: Also update the matched send request
            if ($deliveryRequest->matched_send_id) {
                $sendRequest = \App\Models\SendRequest::find($deliveryRequest->matched_send_id);
                $sendRequest?->update(['status' => 'closed']);
            }

            // Close ALL responses that involve this request (in either field)
            Response::where(function($query) use ($id) {
                $query->where('request_id', $id)
                    ->orWhere('offer_id', $id);
            })
                ->whereIn('status', ['accepted', 'waiting', 'responded', 'pending'])
                ->update(['status' => 'closed']);

            // FIX: Update chat status to closed
//            Chat::where('delivery_request_id', $id)
//                ->update([
//                    'delivery_request_id' => null,
//                    'status' => 'closed'
//                ]);

            DB::commit();

//            Log::info('Delivery request closed successfully', [
//                'request_id' => $id,
//                'user_id' => $user->id
//            ]);

            return response()->json(['message' => 'Delivery request closed successfully']);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error closing delivery request', [
                'request_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Failed to close request'], 500);
        }
    }
}
