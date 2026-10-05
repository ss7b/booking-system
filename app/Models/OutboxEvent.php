<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxEvent extends BookingModel
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}