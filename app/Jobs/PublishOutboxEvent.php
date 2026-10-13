<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

class PublishOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $eventId,
        public readonly string $claimToken,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    public function handle(): void
    {
        $claimed = DB::transaction(function (): bool {
            $event = OutboxEvent::query()->lockForUpdate()->find($this->eventId);

            if (! $event
                || $event->published_at
                || $event->skipped_at
                || $event->dispatch_claim_token !== $this->claimToken
                || ! $event->dispatch_claim_expires_at
                || $event->dispatch_claim_expires_at->isPast()) {
                return false;
            }

            $event->increment('attempts');

            return true;
        });

        if (! $claimed) {
            return;
        }

        try {
            DB::transaction(function (): void {
                $event = OutboxEvent::query()->lockForUpdate()->find($this->eventId);

                if (! $event
                    || $event->published_at
                    || $event->skipped_at
                    || $event->dispatch_claim_token !== $this->claimToken) {
                    return;
                }

                if ($event->aggregate_type === 'Booking' && ! Booking::query()->whereKey($event->aggregate_id)->exists()) {
                    $event->skipped_at = now();
                    $event->skip_reason = 'booking_deleted_before_processing';
                    $event->dispatch_claim_token = null;
                    $event->dispatch_claim_expires_at = null;
                    $event->save();

                    return;
                }

                Event::dispatch($event->event_type, [$event->payload + ['event_id' => $event->getKey()]]);
                $event->published_at = now();
                $event->last_error = null;
                $event->next_attempt_at = null;
                $event->dispatch_claim_token = null;
                $event->dispatch_claim_expires_at = null;
                $event->save();
            });
        } catch (Throwable $exception) {
            OutboxEvent::query()
                ->whereKey($this->eventId)
                ->where('dispatch_claim_token', $this->claimToken)
                ->whereNull('published_at')
                ->update(['last_error' => $exception->getMessage()]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        OutboxEvent::query()
            ->whereKey($this->eventId)
            ->where('dispatch_claim_token', $this->claimToken)
            ->whereNull('published_at')
            ->update([
                'last_error' => $exception?->getMessage(),
                'next_attempt_at' => now()->addMinutes(5),
                'dispatch_claim_token' => null,
                'dispatch_claim_expires_at' => null,
            ]);
    }
}
