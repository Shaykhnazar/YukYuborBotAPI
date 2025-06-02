<?php

namespace App\Http\DTO\SendRequest;

use Carbon\CarbonImmutable;

class CreateSendRequestDTO
{
    public function __construct(
        public string $fromLoc,
        public string $toLoc,
        public ?string $desc,
        public CarbonImmutable $toDate,
        public ?int $price,
        public ?string $currency,
    ) {}

}
