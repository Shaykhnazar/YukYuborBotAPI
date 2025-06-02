<?php

namespace App\Http\DTO\DeliveryRequest;

use Carbon\CarbonImmutable;

class CreateDeliveryRequestDTO
{
    public function __construct(
        public string $fromLoc,
        public string $toLoc,
        public ?string $desc,
        public CarbonImmutable $fromDate,
        public CarbonImmutable $toDate,
        public ?int $price,
        public ?string $currency,
    ) {}

}
