<?php

namespace App\Domain\Booking\DTOs;

use App\Models\Booking;

final readonly class BookingCreationResult
{
    public function __construct(
        public Booking $booking,
        public string $accessToken,
    ) {}
}