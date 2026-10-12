<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Models\Business;
use App\Models\StaffBlock;
use App\Models\User;
use Filament\Facades\Filament;

class StaffBlockPolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageOperations($user, $business);
    }

    public function view(User $user, StaffBlock $block): bool
    {
        return $this->access->canManageStaff($user, $block->staff);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, StaffBlock $block): bool
    {
        return $this->view($user, $block);
    }

    public function delete(User $user, StaffBlock $block): bool
    {
        return $this->view($user, $block);
    }
}
