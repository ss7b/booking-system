<?php

namespace App\Domain\Booking\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Staff;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final class BookingConflictChecker
{
    public function hasConflict(
        Business $business,
        Staff $staff,
        DateTimeImmutable $occupiedStart,
        DateTimeImmutable $occupiedEnd,
        ?string $ignoreBookingId = null,
    ): bool {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Booking conflicts must be checked inside the booking transaction.');
        }

        $utc = new DateTimeZone('UTC');

        return Booking::query()
            ->where('business_id', $business->getKey())
            ->where('staff_id', $staff->getKey())
            ->whereIn('status', [BookingStatus::Held->value, BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
            ->where(fn ($query) => $query
                ->where('status', '!=', BookingStatus::Held->value)
                ->orWhere('hold_expires_at', '>', now()))
            ->when($ignoreBookingId, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->where('occupied_starts_at', '<', $occupiedEnd->setTimezone($utc))
            ->where('occupied_ends_at', '>', $occupiedStart->setTimezone($utc))
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    public function hasCustomerConflict(
        Business $business,
        string $customerId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?string $ignoreBookingId = null,
    ): bool {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Customer booking conflicts must be checked inside the booking transaction.');
        }

        $utc = new DateTimeZone('UTC');

        return Booking::query()
            ->where('business_id', $business->getKey())
            ->where('customer_id', $customerId)
            ->whereIn('status', [BookingStatus::Held->value, BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
            ->where(fn ($query) => $query
                ->where('status', '!=', BookingStatus::Held->value)
                ->orWhere('hold_expires_at', '>', now()))
            ->when($ignoreBookingId, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->where('starts_at', '<', $end->setTimezone($utc))
            ->where('ends_at', '>', $start->setTimezone($utc))
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    public function hasCustomerServiceOnDate(
        Business $business,
        string $customerId,
        string $serviceId,
        DateTimeImmutable $localStart,
        ?string $ignoreBookingId = null,
    ): bool {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Customer service bookings must be checked inside the booking transaction.');
        }

        $utc = new DateTimeZone('UTC');
        $dayStart = $localStart->setTime(0, 0)->setTimezone($utc);
        $dayEnd = $localStart->setTime(0, 0)->modify('+1 day')->setTimezone($utc);

        return Booking::query()
            ->where('business_id', $business->getKey())
            ->where('customer_id', $customerId)
            ->where('service_id', $serviceId)
            ->whereIn('status', [BookingStatus::Held->value, BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
            ->where(fn ($query) => $query
                ->where('status', '!=', BookingStatus::Held->value)
                ->orWhere('hold_expires_at', '>', now()))
            ->when($ignoreBookingId, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->where('starts_at', '>=', $dayStart)
            ->where('starts_at', '<', $dayEnd)
            ->lockForUpdate()
            ->first(['id']) !== null;
    }
}
