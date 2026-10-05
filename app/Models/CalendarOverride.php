<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarOverride extends BookingModel
{
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(AvailabilityCalendar::class, 'calendar_id');
    }
}