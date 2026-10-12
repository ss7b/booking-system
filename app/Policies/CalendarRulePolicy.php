<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Models\AvailabilityCalendar;
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

        return $business && $this->access->canManageOperations($user, $business) && $this->calendarQuery($user)->exists();
    }

    public function view(User $user, CalendarRule $rule): bool
    {
        return $this->access->canManageCalendar($user, $rule->calendar);
    }

    public function create(User $user): bool
    {
        $business = Filament::getTenant();

        return $business && $this->access->canManageOperations($user, $business) && $this->calendarQuery($user)->exists();
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

        return $business
            ? $this->access->calendars($user, $business)
            : AvailabilityCalendar::query()->whereRaw('1 = 0');
    }
}
