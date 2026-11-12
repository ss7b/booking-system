<?php

namespace App\Domain\Availability\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Domain\Availability\ValueObjects\TimeRange;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CapacityEvaluator
{
    /**
     * @return list<TimeRange>
     */
    public function activeBookingRanges(
        Business $business,
        Location $location,
        Service $service,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        ?string $ignoreBookingId = null,
        bool $forUpdate = false,
    ): array {
        $utc = new DateTimeZone('UTC');
        $query = Booking::query()
            ->where('business_id', $business->getKey())
            ->where('location_id', $location->getKey())
            ->where('service_id', $service->getKey())
            ->whereNull('staff_id')
            ->whereIn('status', [
                BookingStatus::Held->value,
                BookingStatus::Confirmed->value,
                BookingStatus::CheckedIn->value,
            ])
            ->where(fn ($query) => $query
                ->where('status', '!=', BookingStatus::Held->value)
                ->orWhere('hold_expires_at', '>', now()))
            ->when($ignoreBookingId, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->where('occupied_starts_at', '<', $windowEnd->setTimezone($utc))
            ->where('occupied_ends_at', '>', $windowStart->setTimezone($utc))
            ->orderBy('occupied_starts_at')
            ->orderBy('id');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->get(['occupied_starts_at', 'occupied_ends_at'])
            ->map(static fn (Booking $booking): TimeRange => new TimeRange(
                $booking->occupied_starts_at,
                $booking->occupied_ends_at,
            ))
            ->all();
    }

    /**
     * @param list<TimeRange> $existingRanges
     */
    public function allows(int $capacity, TimeRange $candidate, array $existingRanges): bool
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Capacity must be at least one.');
        }

        $ranges = [$candidate];

        foreach ($existingRanges as $range) {
            if (! $range->overlaps($candidate)) {
                continue;
            }

            $start = $range->start > $candidate->start ? $range->start : $candidate->start;
            $end = $range->end < $candidate->end ? $range->end : $candidate->end;
            $ranges[] = new TimeRange($start, $end);
        }

        return $this->peakOccupancy($ranges) <= $capacity;
    }

    /**
     * @param list<TimeRange> $ranges
     */
    public function peakOccupancy(array $ranges): int
    {
        $events = [];

        foreach ($ranges as $range) {
            $events[] = ['at' => $range->start, 'change' => 1];
            $events[] = ['at' => $range->end, 'change' => -1];
        }

        usort($events, static function (array $left, array $right): int {
            $time = $left['at'] <=> $right['at'];

            return $time !== 0 ? $time : $left['change'] <=> $right['change'];
        });

        $active = 0;
        $peak = 0;

        foreach ($events as $event) {
            $active += $event['change'];
            $peak = max($peak, $active);
        }

        return $peak;
    }
}
