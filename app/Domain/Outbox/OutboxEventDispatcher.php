<?php

namespace App\Domain\Outbox;

use App\Jobs\PublishOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class OutboxEventDispatcher
{
    private const CLAIM_MINUTES = 15;

    private const DISPATCH_FAILURE_DELAY_MINUTES = 1;

    public function dispatch(string $eventId): bool
    {
        $claimToken = $this->claim($eventId);

        if (! $claimToken) {
            return false;
        }

        try {
            PublishOutboxEvent::dispatch($eventId, $claimToken);

            return true;
        } catch (Throwable $exception) {
            report($exception);
            OutboxEvent::query()
                ->whereKey($eventId)
                ->where('dispatch_claim_token', $claimToken)
                ->whereNull('published_at')
                ->update([
                    'last_error' => $exception->getMessage(),
                    'next_attempt_at' => now()->addMinutes(self::DISPATCH_FAILURE_DELAY_MINUTES),
                    'dispatch_claim_token' => null,
                    'dispatch_claim_expires_at' => null,
                ]);

            return false;
        }
    }

    public function dispatchPending(int $limit = 100): int
    {
        $now = now();
        $eventIds = OutboxEvent::query()
            ->whereNull('published_at')
            ->whereNull('skipped_at')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('dispatch_claim_expires_at')->orWhere('dispatch_claim_expires_at', '<=', $now))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        $dispatched = 0;
        foreach ($eventIds as $eventId) {
            $dispatched += $this->dispatch((string) $eventId) ? 1 : 0;
        }

        return $dispatched;
    }

    private function claim(string $eventId): ?string
    {
        return DB::transaction(function () use ($eventId): ?string {
            $event = OutboxEvent::query()->lockForUpdate()->find($eventId);

            if (! $event
                || $event->published_at
                || $event->skipped_at
                || ($event->next_attempt_at && $event->next_attempt_at->isFuture())
                || ($event->dispatch_claim_expires_at && $event->dispatch_claim_expires_at->isFuture())) {
                return null;
            }

            $claimToken = (string) Str::uuid();
            $event->dispatch_claim_token = $claimToken;
            $event->dispatch_claim_expires_at = now()->addMinutes(self::CLAIM_MINUTES);
            $event->save();

            return $claimToken;
        });
    }
}
