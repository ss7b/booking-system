<?php

namespace App\Policies;

use App\Domain\Authorization\BusinessAccess;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;

class CustomerPolicy
{
    public function __construct(private readonly BusinessAccess $access) {}

    public function viewAny(User $user): bool
    {
        $business = Filament::getTenant();

        return $business instanceof Business && $this->access->canManageBookings($user, $business);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->access->canViewCustomer($user, $customer);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->access->canManageCustomer($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->access->canManageCustomer($user, $customer) && ! $customer->bookings()->exists();
    }
}
