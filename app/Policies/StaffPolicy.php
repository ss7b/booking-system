<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;

class StaffPolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business
            && ($this->access->canManageOperations($user, $business) || $this->access->staff($user, $business)->exists());
    }

    public function view(User $user, Staff $staff): bool
    {
        return $this->access->canManageStaff($user, $staff)
            || ((string) $staff->user_id === (string) $user->getKey() && $this->access->role($user, $staff->business) === BusinessRole::Staff);
    }

    public function create(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageOperations($user, $business);
    }

    public function update(User $user, Staff $staff): bool
    {
        return $this->access->canManageStaff($user, $staff);
    }

    public function delete(User $user, Staff $staff): bool
    {
        return $this->access->canManageStaff($user, $staff)
            && ! $staff->bookings()->exists()
            && ! $staff->blocks()->exists();
    }
}
