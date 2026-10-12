<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->businesses()
            ->where('businesses.is_active', true)
            ->wherePivot('role', BusinessRole::Owner->value)
            ->exists();
    }

    public function view(User $user, Business $business): bool
    {
        return $this->access->canManageBusinessSettings($user, $business);
    }

    public function create(User $user): bool
    {
        return $user->businesses()->wherePivot('role', BusinessRole::Owner->value)->exists();
    }

    public function update(User $user, Business $business): bool
    {
        return $this->access->canManageBusinessSettings($user, $business);
    }

    public function delete(User $user, Business $business): bool
    {
        return $this->access->canManageBusinessSettings($user, $business);
    }
}
