<?php

namespace App\Services\Matching;

use App\Enums\DualStatus;
use App\Enums\RequestStatus;
use App\Enums\ResponseStatus;
use App\Models\Response;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\ResponseRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use Illuminate\Support\Facades\Log;

class ResponseCreationService
{
    public function __construct(
        private ResponseRepositoryInterface $responseRepository,
        private SendRequestRepositoryInterface $sendRequestRepository,
        private DeliveryRequestRepositoryInterface $deliveryRequestRepository
    ) {}

    public function createMatchingResponse(
        int $userId,
        int $responderId,
        string $offerType,
        int $requestId,
        int $offerId
    ): Response {
        $response = $this->responseRepository->updateOrCreateMatching(
            [
                'user_id' => $userId,
                'responder_id' => $responderId,
                'offer_type' => $offerType,
                'request_id' => $requestId,
                'offer_id' => $offerId,
            ],
            [
                'deliverer_status' => DualStatus::PENDING->value,
                'sender_status' => DualStatus::PENDING->value,
                'overall_status' => ResponseStatus::PENDING->value,
                'message' => null
            ]
        );

        $this->updateReceivingRequestStatus($requestId, $offerType);

        Log::info('Matching response created/updated', [
            'user_id' => $userId,
            'responder_id' => $responderId,
            'offer_type' => $offerType,
            'request_id' => $requestId,
            'offer_id' => $offerId,
            'response_id' => $response->id
        ]);

        return $response;
    }

    private function updateReceivingRequestStatus(int $requestId, string $offerType): void
    {
        if ($offerType === 'send') {
            $this->deliveryRequestRepository->updateStatus($requestId, RequestStatus::HAS_RESPONSES->value);

            Log::info('Updated receiving DeliveryRequest status', [
                'delivery_request_id' => $requestId,
                'status' => RequestStatus::HAS_RESPONSES->value
            ]);
        } else {
            $this->sendRequestRepository->updateStatus($requestId, RequestStatus::HAS_RESPONSES->value);

            Log::info('Updated receiving SendRequest status', [
                'send_request_id' => $requestId,
                'status' => RequestStatus::HAS_RESPONSES->value
            ]);
        }
    }
}