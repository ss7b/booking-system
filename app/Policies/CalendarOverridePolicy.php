<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Enums\BusinessRole;
use App\Models\AvailabilityCalendar;
use App\Models\Business;
use App\Models\CalendarOverride;
use App\Models\User;
use Filament\Facades\Filament;

class CalendarOverridePolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageOperations($user, $business)
            && ($this->access->role($user, $business) === BusinessRole::Owner
                || $this->access->calendars($user, $business)->exists());
    }

    public function view(User $user, CalendarOverride $override): bool
    {
        return $this->canManageOverrideCalendarInCurrentBusiness($user, $override);
    }

    public function create(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageOperations($user, $business)
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

    private function canManageOverrideCalendarInCurrentBusiness(User $user, CalendarOverride $override): bool
    {
        $business = Filament::getTenant();
        $calendar = $override->calendar;

        return $business instanceof Business && $calendar instanceof AvailabilityCalendar
            && (string) $calendar->business_id === (string) $business->getKey()
            && $this->access->canManageCalendar($user, $calendar);
    }
}
