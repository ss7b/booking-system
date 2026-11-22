<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

class BookingAvailabilityPicker extends Field
{
    protected string $view = 'filament.forms.components.booking-availability-picker';

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('bookings.picker.availability'))
            ->helperText(__('bookings.picker.helper'));
    }
}
