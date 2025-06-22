<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Delivery\CreateDeliveryRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\Chat;
use App\Service\Matcher;
use App\Service\TelegramUserService;
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
        $dto = $request->getDTO();
        $deliveryReq = new DeliveryRequest(
            [
                'from_location' => $dto->fromLoc,
                'to_location' => $dto->toLoc,
                'description' => $dto->desc ?? null,
                'from_date' => $dto->fromDate->toDateString(),
                'to_date' => $dto->toDate->toDateString(),
                'price' => $dto->price ?? null,
                'currency' => $dto->currency ?? null,
                'user_id' => $this->userService->getUserByTelegramId($request)->id,
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
            if (in_array($deliveryRequest->status, ['matched', 'completed'])) {
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
            Chat::where('delivery_request_id', $id)->delete();

            // Delete the request
            $deliveryRequest->delete();

            DB::commit();

            Log::info('Delivery request deleted successfully', [
                'request_id' => $id,
                'user_id' => $user->id
            ]);

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

        if ($deliveryRequest->status !== 'matched') {
            return response()->json(['error' => 'Can only close matched requests'], 409);
        }

        $deliveryRequest->update(['status' => 'completed']);

        return response()->json(['message' => 'Delivery request closed successfully']);
    }
}
