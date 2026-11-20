<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected static string $view = 'filament.resources.bookings.list-bookings';

    public string $displayMode = 'table';

    public function setDisplayMode(string $displayMode): void
    {
        abort_unless(in_array($displayMode, ['table', 'calendar'], true), 404);

        $this->displayMode = $displayMode;
    }
}
