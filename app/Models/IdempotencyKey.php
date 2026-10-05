<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends BookingModel
{
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}