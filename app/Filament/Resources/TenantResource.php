<?php

namespace App\Filament\Resources;

use App\Domain\Authorization\BusinessAccess;
use App\Models\AvailabilityCalendar;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffBlock;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

abstract class TenantResource extends Resource
{
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $tenant instanceof Business || ! $user) {
            return $query->whereRaw('1 = 0');
        }

        $access = app(BusinessAccess::class);
        $model = static::getModel();

        if ($model === Booking::class) {
            return $access->bookings($user, $tenant);
        }

        if ($model === Location::class) {
            return $access->locations($user, $tenant);
        }

        if ($model === Staff::class) {
            return $access->staff($user, $tenant);
        }

        if ($model === Service::class) {
            return $access->services($user, $tenant);
        }

        if ($model === Customer::class) {
            return $access->customers($user, $tenant);
        }

        if ($model === StaffBlock::class) {
            return $access->staffBlocks($user, $tenant);
        }

        if ($model === AvailabilityCalendar::class) {
            return $access->calendars($user, $tenant);
        }

        return $query;
    }
}
