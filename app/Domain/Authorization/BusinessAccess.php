<?php

namespace App\Domain\Authorization;

use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\AvailabilityCalendar;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BusinessAccess
{
    public function role(User $user, Business $business): ?BusinessRole
    {
        if (! $user->is_active) {
            return null;
        }

        $value = DB::table('business_user')
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');

        return is_string($value) ? BusinessRole::tryFrom($value) : null;
    }

    public function scope(User $user, Business $business): ?BranchAccessScope
    {
        $value = DB::table('business_user')
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->value('access_scope');

        return is_string($value) ? BranchAccessScope::tryFrom($value) : null;
    }

    public function isMember(User $user, Business $business): bool
    {
        return $this->role($user, $business) !== null;
    }

    public function canManageMemberships(User $user, Business $business): bool
    {
        return $this->role($user, $business) === BusinessRole::Owner;
    }

    public function canManageBusinessSettings(User $user, Business $business): bool
    {
        return $this->canManageMemberships($user, $business);
    }

    public function canManageOperations(User $user, Business $business): bool
    {
        return $this->role($user, $business)?->canManageOperations() ?? false;
    }

    public function canManageBookings(User $user, Business $business): bool
    {
        return $this->role($user, $business)?->canManageBookings() ?? false;
    }

    public function canAccessLocation(User $user, Business $business, Location $location): bool
    {
        if ((string) $location->business_id !== (string) $business->getKey() || ! $this->isMember($user, $business)) {
            return false;
        }

        if ($this->role($user, $business) === BusinessRole::Owner || $this->scope($user, $business) === BranchAccessScope::All) {
            return true;
        }

        return DB::table('business_user_locations')
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->where('location_id', $location->getKey())
            ->exists();
    }

    public function canViewLocation(User $user, Location $location): bool
    {
        $business = $location->business;
        if (! $business || ! $this->canAccessLocation($user, $business, $location)) {
            return false;
        }

        if ($this->role($user, $business) !== BusinessRole::Staff) {
            return true;
        }

        return Staff::query()->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->whereHas('locations', fn ($query) => $query->whereKey($location->getKey()))
            ->exists();
    }

    public function locations(User $user, Business $business): Builder
    {
        $query = Location::query()->where('business_id', $business->getKey());

        if (! $this->isMember($user, $business)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->role($user, $business) === BusinessRole::Owner || $this->scope($user, $business) === BranchAccessScope::All) {
            return $query;
        }

        return $query->whereIn('id', DB::table('business_user_locations')
            ->select('location_id')
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey()));
    }

    public function bookings(User $user, Business $business): Builder
    {
        $role = $this->role($user, $business);
        $query = Booking::query()->where('business_id', $business->getKey());

        if ($role === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($role === BusinessRole::Staff) {
            $staffIds = Staff::query()
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->select('id');

            return $query->whereIn('staff_id', $staffIds)
                ->whereIn('location_id', $this->locations($user, $business)->select('id'));
        }

        if (! $role->canManageBookings()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('location_id', $this->locations($user, $business)->select('id'));
    }

    public function staff(User $user, Business $business): Builder
    {
        $role = $this->role($user, $business);
        $query = Staff::query()->where('business_id', $business->getKey());

        if ($role === BusinessRole::Staff) {
            return $query->where('user_id', $user->getKey());
        }

        if (! $role?->canManageOperations()) {
            return $query->whereRaw('1 = 0');
        }

        $allowedLocations = $this->locations($user, $business)->select('id');

        return $query
            ->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $allowedLocations))
            ->whereDoesntHave('locations', fn ($locations) => $locations->whereNotIn('locations.id', $allowedLocations));
    }

    public function services(User $user, Business $business): Builder
    {
        $role = $this->role($user, $business);
        $query = Service::query()->where('business_id', $business->getKey());

        if (! $role || $role === BusinessRole::Staff) {
            return $query->whereRaw('1 = 0');
        }

        $allowedLocations = $this->locations($user, $business)->select('id');

        return $query
            ->whereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $allowedLocations))
            ->whereDoesntHave('locations', fn ($locations) => $locations->whereNotIn('locations.id', $allowedLocations));
    }

    public function customers(User $user, Business $business): Builder
    {
        return Customer::query()
            ->where('business_id', $business->getKey())
            ->whereHas('bookings', fn ($bookings) => $bookings->whereIn('bookings.id', $this->bookings($user, $business)->select('id')));
    }

    public function staffBlocks(User $user, Business $business): Builder
    {
        return StaffBlock::query()
            ->where('business_id', $business->getKey())
            ->whereIn('staff_id', $this->staff($user, $business)->select('id'));
    }

    public function calendars(User $user, Business $business): Builder
    {
        $locationCalendarIds = $this->locations($user, $business)->whereNotNull('calendar_id')->select('calendar_id');
        $staffCalendarIds = $this->staff($user, $business)->whereNotNull('calendar_id')->select('calendar_id');

        return $business->availabilityCalendars()
            ->getQuery()
            ->where(fn ($query) => $query->whereIn('id', $locationCalendarIds)->orWhereIn('id', $staffCalendarIds));
    }

    public function canViewBooking(User $user, Booking $booking): bool
    {
        $business = $booking->business;
        if (! $business) {
            return false;
        }

        return $this->bookings($user, $business)->whereKey($booking->getKey())->exists();
    }

    public function canManageBooking(User $user, Booking $booking): bool
    {
        return $this->canManageBookings($user, $booking->business)
            && $booking->location
            && $this->canAccessLocation($user, $booking->business, $booking->location);
    }

    public function canManageLocation(User $user, Location $location): bool
    {
        $business = $location->business;

        return $business
            && $this->canManageOperations($user, $business)
            && $this->canAccessLocation($user, $business, $location);
    }

    public function canManageMembership(User $user, Business $business): bool
    {
        return $this->canManageMemberships($user, $business);
    }

    public function canManageStaff(User $user, Staff $staff): bool
    {
        $business = $staff->business;
        if (! $business || ! $this->canManageOperations($user, $business)) {
            return false;
        }

        if ($this->role($user, $business) === BusinessRole::Owner) {
            return true;
        }

        $allowedLocations = $this->locations($user, $business)->select('id');

        return $staff->locations()->whereIn('locations.id', $allowedLocations)->exists()
            && ! $staff->locations()->whereNotIn('locations.id', $allowedLocations)->exists();
    }

    public function canManageService(User $user, Service $service): bool
    {
        $business = $service->business;
        if (! $business || ! $this->canManageOperations($user, $business)) {
            return false;
        }

        if ($this->role($user, $business) === BusinessRole::Owner) {
            return true;
        }

        $allowedLocations = $this->locations($user, $business)->select('id');

        return $service->locations()->whereIn('locations.id', $allowedLocations)->exists()
            && ! $service->locations()->whereNotIn('locations.id', $allowedLocations)->exists();
    }

    public function canManageCalendar(User $user, AvailabilityCalendar $calendar): bool
    {
        $business = $calendar->business;
        if (! $business || ! $this->canManageOperations($user, $business)) {
            return false;
        }

        if (! $this->calendars($user, $business)->whereKey($calendar->getKey())->exists()) {
            return false;
        }

        if ($this->role($user, $business) === BusinessRole::Owner) {
            return true;
        }

        $allowedLocations = $this->locations($user, $business)->select('id');
        $allowedStaff = $this->staff($user, $business)->select('id');

        return ! Location::query()->where('calendar_id', $calendar->getKey())->whereNotIn('id', $allowedLocations)->exists()
            && ! Staff::query()->where('calendar_id', $calendar->getKey())->whereNotIn('id', $allowedStaff)->exists();
    }

    public function canViewService(User $user, Service $service): bool
    {
        $business = $service->business;
        if (! $business || ! $this->isMember($user, $business) || $this->role($user, $business) === BusinessRole::Staff) {
            return false;
        }

        return $service->locations()->whereIn('locations.id', $this->locations($user, $business)->select('id'))->exists();
    }

    public function canViewCustomer(User $user, Customer $customer): bool
    {
        $business = $customer->business;
        if (! $business || ! $this->canManageBookings($user, $business)) {
            return false;
        }

        if ($this->role($user, $business) === BusinessRole::Owner) {
            return true;
        }

        return $customer->bookings()
            ->whereIn('bookings.id', $this->bookings($user, $business)->select('id'))
            ->exists();
    }

    public function canManageCustomer(User $user, Customer $customer): bool
    {
        return $this->canManageBookings($user, $customer->business)
            && $this->canViewCustomer($user, $customer);
    }
}
