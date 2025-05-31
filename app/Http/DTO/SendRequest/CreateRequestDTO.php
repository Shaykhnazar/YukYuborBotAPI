<?php

namespace App\Http\DTO\SendRequest;

use Carbon\CarbonImmutable;

class CreateRequestDTO
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
