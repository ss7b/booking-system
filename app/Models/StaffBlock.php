<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffBlock extends BookingModel
{
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'metadata' => 'array'];
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