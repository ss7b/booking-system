<?php

namespace App\Console\Commands;

use App\Domain\Outbox\OutboxEventDispatcher;
use Illuminate\Console\Command;

class PublishOutboxEvents extends Command
{
    protected $signature = 'bookings:publish-outbox {--limit=100}';

    protected $description = 'Dispatch unpublished booking outbox events to the queue.';

    public function handle(OutboxEventDispatcher $dispatcher): int
    {
        $dispatcher->dispatchPending(max(1, (int) $this->option('limit')));

        return self::SUCCESS;
    }
}
