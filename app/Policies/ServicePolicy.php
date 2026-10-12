<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;

class ServicePolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->services($user, $business)->exists();
    }

    public function view(User $user, Service $service): bool
    {
        return $this->access->canViewService($user, $service);
    }

    public function create(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageOperations($user, $business);
    }

    public function update(User $user, Service $service): bool
    {
        return $this->access->canManageService($user, $service);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->access->canManageService($user, $service) && ! $service->bookings()->exists();
    }
}
