<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarRule extends BookingModel
{
    public $timestamps = true;

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(AvailabilityCalendar::class, 'calendar_id');
    }
}