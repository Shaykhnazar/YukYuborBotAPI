<?php

namespace App\Jobs;

use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Services\Matcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MatchRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Job configuration
    public int $timeout = 60;        // Max execution time
    public int $tries = 3;           // Retry attempts
    public int $maxExceptions = 3;   // Max exceptions before failing

    public function __construct(
        private readonly string $requestType,
        private readonly int $requestId
    ) {
        // Set queue priority
        $this->onQueue('matching');

        // Delay execution slightly to avoid race conditions
        $this->delay(now()->addSeconds(2));
    }

    public function handle(Matcher $matcher): void
    {
        Log::info("Processing matching job", [
            'type' => $this->requestType,
            'id' => $this->requestId
        ]);

        try {
            if ($this->requestType === 'send') {
                $sendRequest = SendRequest::find($this->requestId);
                if ($sendRequest) {
                    $matcher->matchSendRequest($sendRequest);
                    Log::info("Send request matched successfully", ['id' => $this->requestId]);
                } else {
                    Log::warning("Send request not found", ['id' => $this->requestId]);
                }
            } elseif ($this->requestType === 'delivery') {
                $deliveryRequest = DeliveryRequest::find($this->requestId);
                if ($deliveryRequest) {
                    $matcher->matchDeliveryRequest($deliveryRequest);
                    Log::info("Delivery request matched successfully", ['id' => $this->requestId]);
                } else {
                    Log::warning("Delivery request not found", ['id' => $this->requestId]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to match requests in background job', [
                'request_type' => $this->requestType,
                'request_id' => $this->requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('MatchRequestsJob permanently failed', [
            'request_type' => $this->requestType,
            'request_id' => $this->requestId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Optional: Send alert to admin
        // NotificationService::alertAdmin('Matching job failed', $exception);
    }
}
