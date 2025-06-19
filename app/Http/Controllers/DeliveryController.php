<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Delivery\CreateDeliveryRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
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

            // Check if request can be deleted (no active responses or matched status)
            if ($deliveryRequest->status === 'matched') {
                return response()->json([
                    'error' => 'Cannot delete request with active collaboration'
                ], 409);
            }

            // Check for active responses
            $hasActiveResponses = Response::where('request_type', 'delivery')
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
            Response::where('request_type', 'delivery')
                ->where('request_id', $id)
                ->delete();

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
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to delete request'
            ], 500);
        }
    }
}
