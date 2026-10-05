<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class PublishOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $eventId) {}

    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $event = OutboxEvent::query()->lockForUpdate()->find($this->eventId);

            if (! $event || $event->published_at) {
                return;
            }

            $event->increment('attempts');
            Event::dispatch($event->event_type, [$event->payload + ['event_id' => $event->getKey()]]);
            $event->published_at = now();
            $event->last_error = null;
            $event->save();
        });
    }

    public function failed(?\Throwable $exception): void
    {
        OutboxEvent::query()
            ->whereKey($this->eventId)
            ->whereNull('published_at')
            ->update(['last_error' => $exception?->getMessage()]);
    }
}