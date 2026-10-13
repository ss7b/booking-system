<?php

namespace App\Console\Commands;

use App\Domain\Notifications\CustomerNotificationService;
use Illuminate\Console\Command;

class DispatchCustomerNotificationReminders extends Command
{
    protected $signature = 'customer-notifications:dispatch-reminders';

    protected $description = 'Queue due customer booking reminders';

    public function handle(CustomerNotificationService $notifications): int
    {
        $this->components->info('Queued '.$notifications->dispatchDueReminders().' customer reminders.');

        return self::SUCCESS;
    }
}
