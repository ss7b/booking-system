<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends BookingModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}