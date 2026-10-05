<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AvailabilityCalendar extends BookingModel
{
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CalendarRule::class, 'calendar_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(CalendarOverride::class, 'calendar_id');
    }
}