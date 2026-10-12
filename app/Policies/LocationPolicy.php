<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;

class LocationPolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageBookings($user, $business)
            && $this->access->locations($user, $business)->exists();
    }

    public function view(User $user, Location $location): bool
    {
        return $this->access->canViewLocation($user, $location);
    }

    public function create(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageBusinessSettings($user, $business);
    }

    public function update(User $user, Location $location): bool
    {
        return $this->access->canManageLocation($user, $location);
    }

    public function delete(User $user, Location $location): bool
    {
        return $this->access->canManageBusinessSettings($user, $location->business)
            && ! $location->bookings()->exists();
    }
}
