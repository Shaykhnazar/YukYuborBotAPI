<?php

namespace App\Jobs;

use App\Models\DeliveryRequest;
use App\Services\GoogleSheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateDeliveryRequestAccepted implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly int $deliveryRequestId,
        private readonly string $responseCreatedTime,
        private readonly string $responseReceivedTime
    ) {}

    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {
            $deliveryRequest = DeliveryRequest::find($this->deliveryRequestId);

            if (!$deliveryRequest) {
                Log::warning('DeliveryRequest not found for Google Sheets accepted update', [
                    'delivery_request_id' => $this->deliveryRequestId,
                ]);
                return;
            }

            Log::info('Updating DeliveryRequest as accepted in Google Sheets', [
                'delivery_request_id' => $deliveryRequest->id,
                'user_id' => $deliveryRequest->user_id,
                'response_received_time' => $this->responseReceivedTime
            ]);

            $googleSheetsService->updateRequestResponseAccepted('delivery', $deliveryRequest->id, $this->responseCreatedTime, $this->responseReceivedTime);

            Log::info('Successfully updated DeliveryRequest as accepted in Google Sheets', [
                'delivery_request_id' => $deliveryRequest->id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update DeliveryRequest as accepted in Google Sheets', [
                'delivery_request_id' => $this->deliveryRequestId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
