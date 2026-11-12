<?php

namespace App\Domain\Booking\Services;

use App\Domain\Availability\Services\CapacityEvaluator;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceLocation;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

final class UpdateServiceLocationSettings
{
    public function __construct(
        private readonly BookingLockManager $locks,
        private readonly CapacityEvaluator $capacity,
    ) {}

    public function execute(
        Business $business,
        Service $service,
        Location $location,
        BookingMode $mode,
        int $capacity,
    ): ServiceLocation {
        if ($capacity < 1) {
            throw new DomainException('CAPACITY_MUST_BE_POSITIVE');
        }

        return DB::transaction(function () use ($business, $service, $location, $mode, $capacity): ServiceLocation {
            Business::query()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();
            $assignment = $this->locks->lockForServiceLocation(
                $business,
                (string) $service->getKey(),
                (string) $location->getKey(),
            );

            if (
                (string) $service->business_id !== (string) $business->getKey()
                || (string) $location->business_id !== (string) $business->getKey()
            ) {
                throw new DomainException('BOOKING_RELATIONSHIP_INVALID');
            }

            $modeChanged = $assignment->booking_mode !== $mode;
            if ($modeChanged && $this->hasActiveBookings($business, $service, $location)) {
                throw new DomainException('BOOKING_MODE_CHANGE_BLOCKED');
            }

            if ($mode === BookingMode::Staffless && $capacity < (int) $assignment->capacity) {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $ranges = $this->capacity->activeBookingRanges(
                    $business,
                    $location,
                    $service,
                    $now,
                    new DateTimeImmutable('9999-12-31 23:59:59', new DateTimeZone('UTC')),
                    forUpdate: true,
                );
                $futureRanges = [];
                foreach ($ranges as $range) {
                    if ($range->end <= $now) {
                        continue;
                    }

                    $futureRanges[] = new \App\Domain\Availability\ValueObjects\TimeRange(
                        $range->start > $now ? $range->start : $now,
                        $range->end,
                    );
                }

                if ($this->capacity->peakOccupancy($futureRanges) > $capacity) {
                    throw new DomainException('CAPACITY_BELOW_CURRENT_OCCUPANCY');
                }
            }

            $assignment->booking_mode = $mode;
            $assignment->capacity = $capacity;
            $assignment->save();

            return $assignment->refresh();
        }, attempts: 3);
    }

    private function hasActiveBookings(Business $business, Service $service, Location $location): bool
    {
        return Booking::query()
            ->where('business_id', $business->getKey())
            ->where('service_id', $service->getKey())
            ->where('location_id', $location->getKey())
            ->where('occupied_ends_at', '>', now())
            ->where(function ($query): void {
                $query->whereIn('status', [
                    BookingStatus::Confirmed->value,
                    BookingStatus::CheckedIn->value,
                ])->orWhere(function ($held): void {
                    $held->where('status', BookingStatus::Held->value)
                        ->where('hold_expires_at', '>', now());
                });
            })
            ->lockForUpdate()
            ->exists();
    }
}
