<?php

namespace App\Http\DTO\Review;

class CreateRequestDTO
{
    public function __construct(
        public int $userId,
        public string $text,
        public int $rating,
        public int $requestId,
        public string $requestType,
    ) {}

}
