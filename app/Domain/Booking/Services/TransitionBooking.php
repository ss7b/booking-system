<?php

namespace App\Domain\Booking\Services;

use App\Enums\BookingStatus;
use App\Enums\BookingMode;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
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
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $expected = Booking::query()->findOrFail($booking->getKey());
            $expectedMode = DB::table('service_locations')
                ->where('service_id', $expected->service_id)
                ->where('location_id', $expected->location_id)
                ->value('booking_mode');

            try {
                return DB::transaction(function () use ($booking, $target, $reason, $actorType, $actorId, $expected, $expectedMode): Booking {
                    if (
                        $expectedMode === BookingMode::StaffRequired->value
                        || (! is_string($expectedMode) && $expected->staff_id !== null)
                    ) {
                        Business::query()->whereKey($expected->business_id)->lockForUpdate()->firstOrFail();
                    }

                    if (is_string($expectedMode)) {
                        $business = Business::query()->findOrFail($expected->business_id);
                        $assignment = app(BookingLockManager::class)->lockForServiceLocation(
                            $business,
                            (string) $expected->service_id,
                            (string) $expected->location_id,
                        );

                        if ($assignment->booking_mode->value !== $expectedMode) {
                            throw new DomainException('BOOKING_MODE_CHANGED_RETRY');
                        }
                    }

                    $booking = Booking::query()
                        ->where('business_id', $expected->business_id)
                        ->lockForUpdate()
                        ->findOrFail($booking->getKey());

                    if (
                        (string) $booking->service_id !== (string) $expected->service_id
                        || (string) $booking->location_id !== (string) $expected->location_id
                    ) {
                        throw new DomainException('BOOKING_CHANGED_RETRY');
                    }

            $current = $booking->status;

            if (! $current->canTransitionTo($target)) {
                throw new DomainException("Cannot transition a booking from {$current->value} to {$target->value}.");
            }

            if ($current === BookingStatus::Held && $target === BookingStatus::Confirmed && (! $booking->hold_expires_at || $booking->hold_expires_at <= now())) {
                throw new DomainException('The booking hold has expired.');
            }

            if ($target === BookingStatus::Expired && (
                $current !== BookingStatus::Held
                || ! $booking->hold_expires_at
                || $booking->hold_expires_at > now()
            )) {
                throw new DomainException('The booking hold has not expired.');
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
                }, attempts: 3);
            } catch (DomainException $exception) {
                if (! in_array($exception->getMessage(), ['BOOKING_MODE_CHANGED_RETRY', 'BOOKING_CHANGED_RETRY'], true) || $attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new DomainException('BOOKING_CHANGED_RETRY');
    }
}