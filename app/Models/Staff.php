<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends BookingModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(AvailabilityCalendar::class, 'calendar_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'staff_services');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'staff_locations')->withPivot('is_active');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(StaffBlock::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}