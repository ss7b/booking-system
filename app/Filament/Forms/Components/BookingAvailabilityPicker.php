<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

class BookingAvailabilityPicker extends Field
{
    protected string $view = 'filament.forms.components.booking-availability-picker';

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Availability')
            ->helperText('Choose a date and time using the live availability calendar.');
    }
}
