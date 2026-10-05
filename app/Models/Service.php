<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends BookingModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'metadata' => 'array'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'staff_services');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'service_locations');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}