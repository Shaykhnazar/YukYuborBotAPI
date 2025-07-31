<?php

namespace App\Http\DTO\DeliveryRequest;

use Carbon\CarbonImmutable;

class CreateDeliveryRequestDTO
{
    public function __construct(
        public int $fromLocId,
        public int $toLocId,
        public ?string $desc,
        public CarbonImmutable $fromDate,
        public CarbonImmutable $toDate,
        public ?int $price,
        public ?string $currency,
    ) {}

}
