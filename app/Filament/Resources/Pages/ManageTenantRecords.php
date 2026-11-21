<?php

namespace App\Filament\Resources\Pages;

use App\Domain\Authorization\BusinessMembershipRequestContext;
use App\Models\Business;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;
use LogicException;

abstract class ManageTenantRecords extends ManageRecords
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
        return [
            CreateAction::make()->mutateFormDataUsing(function (array $data): array {
                $tenant = Filament::getTenant();

                if (! $tenant) {
                    throw new LogicException('A business tenant is required to create this record.');
                }

                $data['business_id'] = $tenant->getKey();

                return $data;
            }),
        ];
    }
}
