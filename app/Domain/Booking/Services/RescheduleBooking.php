<?php

namespace App\Domain\Booking\Services;

use App\Domain\Availability\Services\AvailabilityService;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\Service;
use App\Models\Staff;
use Carbon\Carbon;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

final class RescheduleBooking
{
    public function __construct(
        private readonly BookingLockManager $lockManager,
        private readonly AvailabilityService $availability,
    ) {}

    public function execute(
        Business $business,
        Booking $booking,
        Location $location,
        Staff $staff,
        DateTimeImmutable $start,
        string $actorType,
        ?string $actorId = null,
        ?string $reason = null,
    ): Booking {
        return DB::transaction(function () use ($business, $booking, $location, $staff, $start, $actorType, $actorId, $reason): Booking {
            $booking = Booking::query()
                ->where('business_id', $business->getKey())
                ->lockForUpdate()
                ->findOrFail($booking->getKey());

            $currentStatus = $booking->status;

            if (! in_array($currentStatus, [BookingStatus::Held, BookingStatus::Confirmed], true)) {
                throw new DomainException('INVALID_BOOKING_TRANSITION');
            }

            if ($currentStatus === BookingStatus::Held && (! $booking->hold_expires_at || $booking->hold_expires_at <= now())) {
                throw new DomainException('BOOKING_EXPIRED');
            }

            $service = Service::query()
                ->where('business_id', $business->getKey())
                ->where('is_active', true)
                ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey())->where('locations.is_active', true))
                ->whereKey($booking->service_id)
                ->firstOrFail();

            $staff = Staff::query()
                ->where('business_id', $business->getKey())
                ->where('status', 'active')
                ->whereHas('services', fn ($query) => $query->whereKey($service->getKey()))
                ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey())->where('staff_locations.is_active', true))
                ->findOrFail($staff->getKey());

            $oldLocation = Location::query()
                ->where('business_id', $business->getKey())
                ->findOrFail($booking->location_id);
            $oldStaff = Staff::query()
                ->where('business_id', $business->getKey())
                ->findOrFail($booking->staff_id);

            $timezone = $location->timezone ?: $business->timezone;
            $localStart = $start->setTimezone(new DateTimeZone($timezone));
            $end = $start->add(new DateInterval('PT'.(int) $service->duration_minutes.'M'));
            $occupiedStart = $start->modify('-'.(int) $service->buffer_before_minutes.' minutes');
            $occupiedEnd = $end->modify('+'.(int) $service->buffer_after_minutes.' minutes');

            $this->lockManager->lockStaffRanges($business, [
                [
                    'staff' => $oldStaff,
                    'start' => Carbon::instance($booking->occupied_starts_at),
                    'end' => Carbon::instance($booking->occupied_ends_at),
                    'timezone' => $oldLocation->timezone ?: $business->timezone,
                ],
                [
                    'staff' => $staff,
                    'start' => Carbon::instance($occupiedStart),
                    'end' => Carbon::instance($occupiedEnd),
                    'timezone' => $timezone,
                ],
            ]);

            $availability = $this->availability->forDate(
                $business,
                $location,
                $service,
                $localStart->format('Y-m-d'),
                $staff,
                (string) $booking->getKey(),
            );
            $requestedTimestamp = $start->format('U.u');
            $slotIsAvailable = collect($availability['slots'])->contains(
                static fn (array $slot): bool => (new DateTimeImmutable($slot['start']))->format('U.u') === $requestedTimestamp,
            );

            if (! $slotIsAvailable) {
                throw new DomainException('SLOT_UNAVAILABLE');
            }

            $oldStart = $booking->starts_at->format(DATE_ATOM);
            $oldStaffId = $booking->staff_id;
            $oldLocationId = $booking->location_id;

            $booking->location_id = $location->getKey();
            $booking->staff_id = $staff->getKey();
            $booking->starts_at = $start->setTimezone(new DateTimeZone('UTC'));
            $booking->ends_at = $end->setTimezone(new DateTimeZone('UTC'));
            $booking->occupied_starts_at = $occupiedStart->setTimezone(new DateTimeZone('UTC'));
            $booking->occupied_ends_at = $occupiedEnd->setTimezone(new DateTimeZone('UTC'));
            $booking->save();

            BookingStatusHistory::query()->create([
                'booking_id' => $booking->getKey(),
                'from_status' => $currentStatus->value,
                'to_status' => $currentStatus->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason ?? 'Booking rescheduled.',
                'metadata' => [
                    'from' => [
                        'starts_at' => $oldStart,
                        'staff_id' => $oldStaffId,
                        'location_id' => $oldLocationId,
                    ],
                    'to' => [
                        'starts_at' => $booking->starts_at->format(DATE_ATOM),
                        'staff_id' => $booking->staff_id,
                        'location_id' => $booking->location_id,
                    ],
                ],
            ]);

            OutboxEvent::query()->create([
                'business_id' => $business->getKey(),
                'event_type' => 'BookingRescheduled',
                'aggregate_type' => 'Booking',
                'aggregate_id' => $booking->getKey(),
                'payload' => [
                    'booking_id' => $booking->getKey(),
                    'reference' => $booking->reference,
                    'old_starts_at' => $oldStart,
                    'new_starts_at' => $booking->starts_at->format(DATE_ATOM),
                    'old_staff_id' => $oldStaffId,
                    'new_staff_id' => $booking->staff_id,
                    'old_location_id' => $oldLocationId,
                    'new_location_id' => $booking->location_id,
                ],
                'occurred_at' => now(),
            ]);

            return $booking->refresh();
        }, attempts: 3);
    }
}