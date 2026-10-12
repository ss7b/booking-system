<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Models\CalendarOverride;
use App\Models\User;
use Filament\Facades\Filament;

class CalendarOverridePolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        $business = Filament::getTenant();

        return $business && $this->access->canManageOperations($user, $business)
            && $this->access->calendars($user, $business)->exists();
    }

    public function view(User $user, CalendarOverride $override): bool
    {
        return $this->access->canManageCalendar($user, $override->calendar);
    }

    public function create(User $user): bool
    {
        $business = Filament::getTenant();

        return $business && $this->access->canManageOperations($user, $business)
            && $this->access->calendars($user, $business)->exists();
    }

    public function update(User $user, CalendarOverride $override): bool
    {
        return $this->view($user, $override);
    }

    public function delete(User $user, CalendarOverride $override): bool
    {
        return $this->view($user, $override);
    }
}
