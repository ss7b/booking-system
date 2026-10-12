<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->businesses()->exists();
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->access->canViewBooking($user, $booking);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Booking $booking): bool
    {
        return $this->access->canManageBooking($user, $booking);
    }

    public function reschedule(User $user, Booking $booking): bool
    {
        return $this->access->canManageBooking($user, $booking);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->access->canManageBooking($user, $booking);
    }

    public function confirm(User $user, Booking $booking): bool
    {
        return $this->access->canManageBooking($user, $booking);
    }
}
