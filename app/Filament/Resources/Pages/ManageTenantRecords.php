<?php

namespace App\Filament\Resources\Pages;

use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;
use LogicException;

abstract class ManageTenantRecords extends ManageRecords
{
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
