<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireHeldBookings extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Expire booking holds whose expiration time has passed.';

    public function handle(): int
    {
        Booking::query()
            ->where('status', BookingStatus::Held->value)
            ->where('hold_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($bookings): void {
                foreach ($bookings as $booking) {
                    DB::transaction(function () use ($booking): void {
                        $locked = Booking::query()->lockForUpdate()->find($booking->getKey());

                        if (! $locked || $locked->status !== BookingStatus::Held || $locked->hold_expires_at > now()) {
                            return;
                        }

                        $locked->status = BookingStatus::Expired;
                        $locked->save();

                        BookingStatusHistory::query()->create([
                            'booking_id' => $locked->getKey(),
                            'from_status' => BookingStatus::Held->value,
                            'to_status' => BookingStatus::Expired->value,
                            'actor_type' => 'system',
                            'reason' => 'Booking hold expired.',
                        ]);

                        OutboxEvent::query()->create([
                            'business_id' => $locked->business_id,
                            'event_type' => 'BookingExpired',
                            'aggregate_type' => 'Booking',
                            'aggregate_id' => $locked->getKey(),
                            'payload' => ['booking_id' => $locked->getKey(), 'reference' => $locked->reference],
                            'occurred_at' => now(),
                        ]);
                    });
                }
            });

        return self::SUCCESS;
    }
}