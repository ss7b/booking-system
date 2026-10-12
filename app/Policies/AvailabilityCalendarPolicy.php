<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Models\AvailabilityCalendar;
use App\Models\Business;
use App\Models\User;
use Filament\Facades\Filament;

class AvailabilityCalendarPolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageOperations($user, $business)
            && $this->access->calendars($user, $business)->exists();
    }

    public function view(User $user, AvailabilityCalendar $calendar): bool
    {
        return $this->access->canManageCalendar($user, $calendar);
    }

    public function create(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageOperations($user, $business);
    }

    public function update(User $user, AvailabilityCalendar $calendar): bool
    {
        return $this->access->canManageCalendar($user, $calendar);
    }

    public function delete(User $user, AvailabilityCalendar $calendar): bool
    {
        return $this->access->canManageCalendar($user, $calendar);
    }
}
