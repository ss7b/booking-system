<?php

namespace App\Domain\Booking\Services;

use DateTimeInterface;
use Illuminate\Support\Str;

final class BookingReferenceGenerator
{
    public function generate(DateTimeInterface $startsAt): string
    {
        return 'BK-'.$startsAt->format('Ymd').'-'.Str::upper(Str::random(6));
    }
}