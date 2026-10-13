<?php

namespace App\Jobs;

use App\Domain\Notifications\CustomerNotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverCustomerNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $notificationId) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(CustomerNotificationDelivery $delivery): void
    {
        $delivery->deliver($this->notificationId);
    }
}
