<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Filament\Resources\Pages\ManageTenantRecords;
use App\Filament\Resources\ServiceResource;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

class ManageServices extends ManageTenantRecords
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $business = Filament::getTenant();
                    abort_unless($business instanceof Business, 500);
                    $data['business_id'] = $business->getKey();

                    return $data;
                })
                ->using(function (array $data): Service {
                    $settings = array_values($data['location_settings'] ?? []);
                    unset($data['location_settings']);

                    $business = Filament::getTenant();
                    $user = Filament::auth()->user();
                    abort_unless($business instanceof Business && $user instanceof User, 500);

                    return DB::transaction(function () use ($business, $user, $data, $settings): Service {
                        $service = Service::query()->create($data);
                        ServiceResource::saveLocationSettings($business, $service, $user, $settings);

                        return $service;
                    }, attempts: 3);
                }),
        ];
    }
}
