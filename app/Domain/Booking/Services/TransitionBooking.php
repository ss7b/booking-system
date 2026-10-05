<?php

namespace App\Domain\Booking\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\OutboxEvent;
use DomainException;
use Illuminate\Support\Facades\DB;

final class TransitionBooking
{
    public function execute(
        Booking $booking,
        BookingStatus $target,
        ?string $reason = null,
        string $actorType = 'system',
        ?string $actorId = null,
    ): Booking
    {
        return DB::transaction(function () use ($booking, $target, $reason, $actorType, $actorId): Booking {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->getKey());
            $current = $booking->status;

            if (! $current->canTransitionTo($target)) {
                throw new DomainException("Cannot transition a booking from {$current->value} to {$target->value}.");
            }

            if ($current === BookingStatus::Held && $target === BookingStatus::Confirmed && (! $booking->hold_expires_at || $booking->hold_expires_at <= now())) {
                throw new DomainException('The booking hold has expired.');
            }

            $booking->status = $target;

            if ($target === BookingStatus::Confirmed) {
                $booking->confirmed_at = now();
            } elseif ($target === BookingStatus::Cancelled) {
                $booking->cancelled_at = now();
            } elseif ($target === BookingStatus::Completed) {
                $booking->completed_at = now();
            }

            $booking->save();

            BookingStatusHistory::query()->create([
                'booking_id' => $booking->getKey(),
                'from_status' => $current->value,
                'to_status' => $target->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
            ]);

            OutboxEvent::query()->create([
                'business_id' => $booking->business_id,
                'event_type' => 'Booking'.str_replace(' ', '', ucwords(str_replace('_', ' ', $target->value))),
                'aggregate_type' => 'Booking',
                'aggregate_id' => $booking->getKey(),
                'payload' => [
                    'booking_id' => $booking->getKey(),
                    'reference' => $booking->reference,
                    'from_status' => $current->value,
                    'to_status' => $target->value,
                ],
                'occurred_at' => now(),
            ]);

            return $booking->refresh();
        });
    }
}