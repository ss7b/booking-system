<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;

abstract class TenantResource extends Resource
{
    protected static ?string $tenantOwnershipRelationshipName = 'business';
}
