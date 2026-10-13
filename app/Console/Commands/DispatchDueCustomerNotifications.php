<?php

namespace App\Console\Commands;

use App\Domain\Notifications\CustomerNotificationService;
use Illuminate\Console\Command;

class DispatchDueCustomerNotifications extends Command
{
    protected $signature = 'customer-notifications:dispatch-due';

    protected $description = 'Dispatch customer notifications whose retry time has arrived';

    public function handle(CustomerNotificationService $notifications): int
    {
        $this->components->info('Dispatched '.$notifications->dispatchDueRetries().' customer notification retries.');

        return self::SUCCESS;
    }
}
