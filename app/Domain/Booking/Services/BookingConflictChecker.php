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
}
