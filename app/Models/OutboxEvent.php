<?php

namespace App\Models;

use App\Domain\Outbox\OutboxEventDispatcher;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class OutboxEvent extends BookingModel
{
    protected static function booted(): void
    {
        static::created(function (self $event): void {
            $eventId = (string) $event->getKey();

            DB::afterCommit(function () use ($eventId): void {
                app(OutboxEventDispatcher::class)->dispatch($eventId);
            });
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'dispatch_claim_expires_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'skipped_at' => 'immutable_datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
