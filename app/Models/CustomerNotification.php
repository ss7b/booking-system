<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerNotification extends BookingModel
{
    protected $fillable = [
        'business_id',
        'booking_id',
        'customer_id',
        'source_outbox_event_id',
        'event_type',
        'idempotency_key',
        'channels',
        'appointment_starts_at',
        'appointment_fingerprint',
        'status',
        'next_attempt_at',
        'failed_at',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'appointment_starts_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'resent_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CustomerNotificationAttempt::class);
    }
}
