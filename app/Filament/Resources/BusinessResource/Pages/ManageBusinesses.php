<?php

namespace App\Filament\Resources\BusinessResource\Pages;

use App\Filament\Resources\BusinessResource;
use App\Models\Business;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageBusinesses extends ManageRecords
{
    protected static string $resource = BusinessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->after(function (Business $record): void {
                $record->users()->syncWithoutDetaching([
                    Filament::auth()->id() => ['role' => 'owner'],
                ]);
            }),
        ];
    }
}
