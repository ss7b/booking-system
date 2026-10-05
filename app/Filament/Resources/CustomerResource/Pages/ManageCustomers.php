<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\Pages\ManageTenantRecords;

class ManageCustomers extends ManageTenantRecords
{
    protected static string $resource = CustomerResource::class;
}
