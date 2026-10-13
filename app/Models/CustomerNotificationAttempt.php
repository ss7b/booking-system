<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNotificationAttempt extends BookingModel
{
    public $timestamps = false;

    protected $fillable = [
        'customer_notification_id',
        'channel',
        'attempt_number',
        'status',
        'provider_message_id',
        'error_code',
        'error_message',
        'initiated_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(CustomerNotification::class, 'customer_notification_id');
    }
}
