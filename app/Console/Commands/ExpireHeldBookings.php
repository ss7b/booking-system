<?php

namespace App\Console\Commands;

use App\Domain\Booking\Services\TransitionBooking;
use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Command;
use DomainException;

class ExpireHeldBookings extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Expire booking holds whose expiration time has passed.';

    public function handle(TransitionBooking $transition): int
    {
        Booking::query()
            ->where('status', BookingStatus::Held->value)
            ->where('hold_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($bookings): void {
                foreach ($bookings as $booking) {
                    try {
                        $transition->execute($booking, BookingStatus::Expired, 'Booking hold expired.', 'system');
                    } catch (DomainException $exception) {
                        if (
                            $exception->getMessage() !== 'The booking hold has not expired.'
                            && ! str_starts_with($exception->getMessage(), 'Cannot transition a booking from ')
                        ) {
                            throw $exception;
                        }
                    }
                }
            });

        return self::SUCCESS;
    }
}