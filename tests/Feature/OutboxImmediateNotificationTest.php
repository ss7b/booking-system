<?php

namespace Tests\Feature;

use App\Domain\Booking\Services\TransitionBooking;
use App\Enums\BookingStatus;
use App\Jobs\PublishOutboxEvent;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OutboxImmediateNotificationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_recovery_publisher_does_not_requeue_an_event_while_its_async_job_is_claimed(): void
    {
        config(['queue.default' => 'database']);
        [$business, $booking] = $this->makeBookingFixture();

        $event = DB::transaction(fn (): OutboxEvent => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingConfirmed',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]));

        $this->assertNull($event->fresh()->published_at);
        $this->assertDatabaseCount('jobs', 1);

        $this->artisan('bookings:publish-outbox')->assertSuccessful();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertNull($event->fresh()->published_at);

        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
        $this->assertNotNull($event->fresh()->published_at);
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_pending_event_for_a_deleted_booking_is_explicitly_skipped(): void
    {
        [$business, $booking, $owner] = $this->makeBookingFixture();
        $event = OutboxEvent::withoutEvents(fn (): OutboxEvent => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingConfirmed',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]));

        $booking->delete();
        $this->artisan('bookings:publish-outbox')->assertSuccessful();

        $this->assertNull($event->fresh()->published_at);
        $this->assertNotNull($event->fresh()->skipped_at);
        $this->assertSame('booking_deleted_before_processing', $event->fresh()->skip_reason);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $owner->getKey()]);
    }

    public function test_notification_persisted_before_booking_deletion_remains_as_a_safe_tombstone(): void
    {
        [$business, $booking, $owner] = $this->makeBookingFixture();
        $event = OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingConfirmed',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]);
        $notificationId = DB::table('user_notifications')->where('user_id', $owner->getKey())->value('id');

        $booking->delete();

        $this->assertNotNull($event->fresh()->published_at);
        $this->assertNull($event->fresh()->skipped_at);
        $this->assertNull($event->fresh()->skip_reason);
        $this->assertDatabaseHas('user_notifications', ['id' => $notificationId, 'booking_id' => null]);
    }

    public function test_failed_dispatch_releases_claim_for_retry(): void
    {
        [$business, $booking] = $this->makeBookingFixture();
        $bus = \Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue unavailable'));
        Bus::swap($bus);

        $event = DB::transaction(fn (): OutboxEvent => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingConfirmed',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]));

        $event->refresh();
        $this->assertNull($event->published_at);
        $this->assertNull($event->dispatch_claim_token);
        $this->assertSame('queue unavailable', $event->last_error);
        $this->assertTrue($event->next_attempt_at->isFuture());
    }

    public function test_expired_claim_is_reclaimed_and_a_backed_off_old_event_does_not_block_newer_events(): void
    {
        config(['queue.default' => 'database']);
        [$business, $booking] = $this->makeBookingFixture();
        $createPendingEvent = fn (): OutboxEvent => OutboxEvent::withoutEvents(fn (): OutboxEvent => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingConfirmed',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]));

        $backedOff = $createPendingEvent();
        $backedOff->next_attempt_at = now()->addHour();
        $backedOff->save();
        $expired = $createPendingEvent();
        $expired->dispatch_claim_token = (string) Str::uuid();
        $expired->dispatch_claim_expires_at = now()->subMinute();
        $expired->save();
        $newer = $createPendingEvent();

        $this->artisan('bookings:publish-outbox')->assertSuccessful();

        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertNotSame((string) $expired->dispatch_claim_token, $expired->fresh()->dispatch_claim_token);
        $this->assertNotNull($newer->fresh()->dispatch_claim_token);
        $this->assertNull($backedOff->fresh()->dispatch_claim_token);
    }

    public function test_concurrent_mysql_publishers_claim_and_enqueue_a_pending_event_once(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('proc_open')) {
            $this->markTestSkipped('This concurrency test requires MySQL and proc_open.');
        }

        config(['queue.default' => 'database']);
        [$business, $booking] = $this->makeBookingFixture();
        $event = OutboxEvent::withoutEvents(fn (): OutboxEvent => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingConfirmed',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]));

        $connection = config('database.connections.'.config('database.default'));
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => (string) ($connection['host'] ?? ''),
            'DB_PORT' => (string) ($connection['port'] ?? ''),
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) ($connection['username'] ?? ''),
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'array',
        ];

        foreach (['unix_socket' => 'DB_SOCKET', 'charset' => 'DB_CHARSET'] as $configKey => $environmentKey) {
            if (! empty($connection[$configKey])) {
                $environment[$environmentKey] = (string) $connection[$configKey];
            }
        }

        $command = [PHP_BINARY, base_path('artisan'), 'bookings:publish-outbox', '--limit=10'];
        $processes = [
            new Process($command, base_path(), $environment),
            new Process($command, base_path(), $environment),
        ];

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        }

        $this->assertDatabaseCount('jobs', 1);
        $this->assertNotNull($event->fresh()->dispatch_claim_token);
        $this->assertNull($event->fresh()->published_at);
    }

    public function test_transient_async_failure_is_released_and_recovery_can_complete_the_event(): void
    {
        config(['queue.default' => 'database']);
        [$business, $booking, $owner] = $this->makeBookingFixture();
        $failOnce = true;
        Event::listen('BookingRescheduled', function (array $payload) use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('simulated async listener failure');
            }
        });

        $event = DB::transaction(fn (): OutboxEvent => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingRescheduled',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]));

        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
        $this->assertNull($event->fresh()->published_at);
        $this->assertSame('simulated async listener failure', $event->fresh()->last_error);
        $this->assertSame(1, $event->fresh()->attempts);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $owner->getKey()]);

        DB::table('jobs')->update(['available_at' => now()->subSecond()->timestamp]);
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

        $this->assertNotNull($event->fresh()->published_at);
        $this->assertNull($event->fresh()->last_error);
        $this->assertSame(2, $event->fresh()->attempts);
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->getKey(),
            'booking_id' => $booking->getKey(),
            'notification_type' => 'booking.rescheduled',
        ]);
    }

    public function test_committed_events_publish_immediately_and_rolled_back_events_do_not(): void
    {
        [$business, $booking, $owner] = $this->makeBookingFixture();

        $event = DB::transaction(fn (): OutboxEvent => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingConfirmed',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]));

        $this->assertNotNull($event->fresh()->published_at);
        $this->assertDatabaseHas('user_notifications', [
            'business_id' => $business->getKey(),
            'user_id' => $owner->getKey(),
            'booking_id' => $booking->getKey(),
            'notification_type' => 'booking.created',
        ]);
        $this->assertDatabaseCount('user_notifications', 1);

        PublishOutboxEvent::dispatch((string) $event->getKey(), 'duplicate-token');
        $this->assertDatabaseCount('user_notifications', 1);

        $booking = app(TransitionBooking::class)->execute($booking, BookingStatus::Cancelled, 'Customer request');
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->getKey(),
            'booking_id' => $booking->getKey(),
            'notification_type' => 'booking.cancelled',
        ]);
        $this->assertDatabaseCount('user_notifications', 2);

        try {
            DB::transaction(function () use ($business, $booking): void {
                OutboxEvent::query()->create([
                    'business_id' => $business->getKey(),
                    'event_type' => 'BookingCancelled',
                    'aggregate_type' => 'Booking',
                    'aggregate_id' => $booking->getKey(),
                    'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
                    'occurred_at' => now(),
                ]);

                throw new \RuntimeException('rollback booking transaction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback booking transaction', $exception->getMessage());
        }

        $this->assertDatabaseCount('user_notifications', 2);
        $this->assertDatabaseCount('outbox_events', 2);

        $recoveryEvent = OutboxEvent::withoutEvents(fn (): OutboxEvent => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingCancelled',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]));

        $this->assertNull($recoveryEvent->fresh()->published_at);
        $this->artisan('bookings:publish-outbox')->assertSuccessful();
        $this->assertNotNull($recoveryEvent->fresh()->published_at);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->getKey(),
            'booking_id' => $booking->getKey(),
            'notification_type' => 'booking.cancelled',
        ]);
        $this->assertDatabaseCount('user_notifications', 3);

        $failOnce = true;
        Event::listen('BookingRescheduled', function (array $payload) use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('simulated notification listener failure');
            }
        });

        $failedEvent = OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingRescheduled',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
            'occurred_at' => now(),
        ]);

        $this->assertNull($failedEvent->fresh()->published_at);
        $this->assertNotNull($failedEvent->fresh()->last_error);
        $this->assertSame(1, $failedEvent->fresh()->attempts);
        $this->assertDatabaseCount('user_notifications', 3);

        DB::table('outbox_events')->where('id', $failedEvent->getKey())->update(['next_attempt_at' => now()->subSecond()]);
        $this->artisan('bookings:publish-outbox')->assertSuccessful();
        $this->assertNotNull($failedEvent->fresh()->published_at);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->getKey(),
            'booking_id' => $booking->getKey(),
            'notification_type' => 'booking.rescheduled',
        ]);
        $this->assertDatabaseCount('user_notifications', 4);

        PublishOutboxEvent::dispatch((string) $failedEvent->getKey(), 'duplicate-token');
        $this->assertDatabaseCount('user_notifications', 4);
    }

    private function makeBookingFixture(): array
    {
        $business = Business::query()->create([
            'name' => 'Outbox Immediate Business',
            'slug' => 'outbox-immediate-'.fake()->unique()->numerify('#####'),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Main',
            'slug' => 'main',
            'is_active' => true,
        ]);
        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Consultation',
            'slug' => 'consultation-'.fake()->unique()->numerify('#####'),
            'duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'is_active' => true,
        ]);
        $staff = Staff::query()->create(['business_id' => $business->getKey(), 'name' => 'Staff', 'status' => 'active']);
        $customer = Customer::query()->create(['business_id' => $business->getKey(), 'name' => 'Customer', 'phone' => '0500000000']);
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $booking = Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $location->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => 'BK-OUTBOX-'.fake()->unique()->numerify('#####'),
            'status' => BookingStatus::Confirmed,
            'source' => 'test',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addMinutes(30),
            'occupied_starts_at' => now()->addDays(2),
            'occupied_ends_at' => now()->addDays(2)->addMinutes(30),
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => 30,
        ]);

        return [$business, $booking, $owner];
    }
}
