<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingDayLock extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['booking_date' => 'date'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}