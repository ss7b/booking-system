<?php

namespace App\Console\Commands;

use App\Models\CustomerNotificationAttempt;
use Illuminate\Console\Command;

class PruneCustomerNotificationAttempts extends Command
{
    protected $signature = 'customer-notifications:prune-attempts';

    protected $description = 'Delete customer delivery attempt details older than 30 days';

    public function handle(): int
    {
        $deleted = CustomerNotificationAttempt::query()->where('created_at', '<', now()->subDays(30))->delete();
        $this->components->info("Pruned {$deleted} customer notification attempts.");

        return self::SUCCESS;
    }
}
