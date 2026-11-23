<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Enums\BusinessRole;
use App\Models\AvailabilityCalendar;
use App\Models\Business;
use App\Models\CalendarRule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

class CalendarRulePolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        $business = Filament::getTenant();

        if (! $business instanceof Business || ! $this->access->canManageOperations($user, $business)) {
            return false;
        }

        return $this->access->role($user, $business) === BusinessRole::Owner || $this->calendarQuery($user)->exists();
    }

    public function view(User $user, CalendarRule $rule): bool
    {
        return $this->canManageRuleCalendarInCurrentBusiness($user, $rule);
    }

    public function create(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageOperations($user, $business)
            && $this->calendarQuery($user)->exists();
    }

    public function update(User $user, CalendarRule $rule): bool
    {
        return $this->view($user, $rule);
    }

    public function delete(User $user, CalendarRule $rule): bool
    {
        return $this->view($user, $rule);
    }

    private function calendarQuery(User $user): Builder
    {
        $business = Filament::getTenant();

        return $business instanceof Business
            ? $this->access->calendars($user, $business)
            : AvailabilityCalendar::query()->whereRaw('1 = 0');
    }

    private function canManageRuleCalendarInCurrentBusiness(User $user, CalendarRule $rule): bool
    {
        $business = Filament::getTenant();
        $calendar = $rule->calendar;

        return $business instanceof Business && $calendar
            && (string) $calendar->business_id === (string) $business->getKey()
            && $this->access->canManageCalendar($user, $calendar);
    }
}
