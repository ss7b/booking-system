<?php

namespace App\Filament\Resources\Pages;

use App\Domain\Authorization\BusinessMembershipRequestContext;
use App\Models\Business;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

abstract class ManageScopedRecords extends ManageRecords
{
    public function boot(): void
    {
        $user = Filament::auth()->user();
        $business = Filament::getTenant();

        if (! $user instanceof User || ! $business instanceof Business) {
            return;
        }

        $resource = static::getResource();
        BusinessMembershipRequestContext::beginForModel($resource::getModel(), $user, $business);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
