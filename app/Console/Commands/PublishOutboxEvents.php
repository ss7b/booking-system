<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;

class PublishOutboxEvents extends Command
{
    protected $signature = 'bookings:publish-outbox {--limit=100}';

    protected $description = 'Dispatch unpublished booking outbox events to the queue.';

    public function handle(): int
    {
        OutboxEvent::query()
            ->whereNull('published_at')
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id')
            ->each(fn (string $id) => PublishOutboxEvent::dispatch($id));

        return self::SUCCESS;
    }
}