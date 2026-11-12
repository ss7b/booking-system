<?php

namespace Tests\Feature;

use App\Domain\Booking\Services\BookingLockManager;
use App\Domain\Booking\Services\CreateBooking;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\AvailabilityCalendar;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StafflessResourceLockingConcurrencyTest extends TestCase
{
    /** @var list<string> */
    private array $businessIds = [];

    public function test_two_concurrent_holds_cannot_consume_the_last_capacity_unit(): void
    {
        $fixture = $this->fixture();
        $payloads = [
            $this->createPayload($fixture, 0, 0, '09:00', true),
            $this->createPayload($fixture, 0, 0, '09:00', true),
        ];

        $results = $this->runLockedRace($fixture, $payloads, [[0, 0]]);

        $this->assertSame(1, $this->countStatus($fixture, BookingStatus::Held));
        $this->assertSame(1, $this->countResult($results, 'created'));
        $this->assertSame(1, $this->countRejection($results, 'SLOT_UNAVAILABLE'));
    }

    public function test_expired_hold_confirmation_racing_expiration_releases_capacity_once(): void
    {
        $fixture = $this->fixture();
        $hold = $this->createBooking($fixture, 0, 0, '09:00', true);
        $expiresAt = now()->addSecond();
        $hold->booking->forceFill(['hold_expires_at' => $expiresAt])->save();
        $payloads = [
            [
                'operation' => 'transition',
                'business_id' => $fixture['business']->getKey(),
                'service_id' => $fixture['services'][0]->getKey(),
                'booking_id' => $hold->booking->getKey(),
                'target_status' => BookingStatus::Confirmed->value,
            ],
            [
                'operation' => 'transition',
                'business_id' => $fixture['business']->getKey(),
                'service_id' => $fixture['services'][0]->getKey(),
                'booking_id' => $hold->booking->getKey(),
                'target_status' => BookingStatus::Expired->value,
            ],
        ];

        $results = $this->runLockedRace($fixture, $payloads, [[0, 0]], 1_200_000);

        $this->assertSame(BookingStatus::Expired, $hold->booking->fresh()->status);
        $this->assertSame(1, $this->countResult($results, 'transitioned'));
        $this->assertSame(1, count(array_filter(
            $results,
            static fn (array $result): bool => $result['status'] === 'rejected',
        )));
    }

    public function test_two_reschedules_to_one_staffless_resource_cannot_exceed_capacity(): void
    {
        $fixture = $this->fixture(locationCount: 3);
        $first = $this->createBooking($fixture, 0, 0, '09:00');
        $second = $this->createBooking($fixture, 1, 0, '09:00');
        $targetId = (string) $fixture['locations'][2]->getKey();
        $payloads = [
            $this->reschedulePayload($fixture, $first->booking, $targetId, '10:00'),
            $this->reschedulePayload($fixture, $second->booking, $targetId, '10:00'),
        ];

        $results = $this->runLockedRace($fixture, $payloads, [[0, 0], [1, 0], [2, 0]]);

        $this->assertSame(1, $this->countResult($results, 'rescheduled'));
        $this->assertSame(1, $this->countRejection($results, 'SLOT_UNAVAILABLE'));
        $this->assertSame(1, $this->countBookingsAt($fixture, $targetId, '10:00'));
    }

    public function test_rescheduling_and_new_booking_racing_for_one_resource_serialize_capacity(): void
    {
        $fixture = $this->fixture(locationCount: 2);
        $existing = $this->createBooking($fixture, 0, 0, '09:00');
        $targetId = (string) $fixture['locations'][1]->getKey();
        $payloads = [
            $this->reschedulePayload($fixture, $existing->booking, $targetId, '10:00'),
            $this->createPayload($fixture, 1, 0, '10:00'),
        ];

        $results = $this->runLockedRace($fixture, $payloads, [[0, 0], [1, 0]]);

        $this->assertSame(1, $this->countResult($results, 'rescheduled') + $this->countResult($results, 'created'));
        $this->assertSame(1, $this->countRejection($results, 'SLOT_UNAVAILABLE'));
        $this->assertSame(1, $this->countBookingsAt($fixture, $targetId, '10:00'));
    }

    public function test_cancellation_racing_new_booking_never_overbooks_the_resource(): void
    {
        $fixture = $this->fixture();
        $existing = $this->createBooking($fixture, 0, 0, '09:00');
        $payloads = [
            [
                'operation' => 'transition',
                'business_id' => $fixture['business']->getKey(),
                'service_id' => $fixture['services'][0]->getKey(),
                'booking_id' => $existing->booking->getKey(),
                'target_status' => BookingStatus::Cancelled->value,
            ],
            $this->createPayload($fixture, 0, 0, '09:00'),
        ];

        $results = $this->runLockedRace($fixture, $payloads, [[0, 0]]);

        $this->assertSame(BookingStatus::Cancelled, $existing->booking->fresh()->status);
        $this->assertSame($this->countResult($results, 'created'), $this->countStatus($fixture, BookingStatus::Confirmed));
        $this->assertSame(1, $this->countResult($results, 'transitioned'));
        $this->assertSame(1, $this->countResult($results, 'created') + $this->countRejection($results, 'SLOT_UNAVAILABLE'));
    }

    public function test_capacity_reduction_racing_creation_preserves_the_capacity_invariant(): void
    {
        $fixture = $this->fixture(capacity: 2);
        $this->createBooking($fixture, 0, 0, '09:00');
        $payloads = [
            [
                'operation' => 'settings',
                'business_id' => $fixture['business']->getKey(),
                'service_id' => $fixture['services'][0]->getKey(),
                'location_id' => $fixture['locations'][0]->getKey(),
                'booking_mode' => BookingMode::Staffless->value,
                'capacity' => 1,
            ],
            $this->createPayload($fixture, 0, 0, '09:00'),
        ];

        $results = $this->runLockedRace($fixture, $payloads, [[0, 0]]);

        $capacity = (int) DB::table('service_locations')
            ->where('service_id', $fixture['services'][0]->getKey())
            ->where('location_id', $fixture['locations'][0]->getKey())
            ->value('capacity');
        $activeBookings = $this->countStatus($fixture, BookingStatus::Confirmed);

        $this->assertLessThanOrEqual($capacity, $activeBookings, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $this->countResult($results, 'created') + $this->countResult($results, 'settings_updated'));
        if ($this->countResult($results, 'created') === 1) {
            $this->assertSame(1, $this->countRejection($results, 'CAPACITY_BELOW_CURRENT_OCCUPANCY'), json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame(2, $capacity);
            $this->assertSame(2, $activeBookings);
        } else {
            $this->assertSame(1, $this->countRejection($results, 'SLOT_UNAVAILABLE'), json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame(1, $capacity);
            $this->assertSame(1, $activeBookings);
        }
    }

    public function test_booking_mode_change_racing_creation_cannot_create_a_mismatched_booking(): void
    {
        $fixture = $this->fixture();
        $payloads = [
            [
                'operation' => 'settings',
                'business_id' => $fixture['business']->getKey(),
                'service_id' => $fixture['services'][0]->getKey(),
                'location_id' => $fixture['locations'][0]->getKey(),
                'booking_mode' => BookingMode::StaffRequired->value,
                'capacity' => 1,
            ],
            $this->createPayload($fixture, 0, 0, '09:00'),
        ];

        $results = $this->runLockedRace($fixture, $payloads, [[0, 0]]);
        $mode = DB::table('service_locations')
            ->where('service_id', $fixture['services'][0]->getKey())
            ->where('location_id', $fixture['locations'][0]->getKey())
            ->value('booking_mode');
        $booking = Booking::query()->where('business_id', $fixture['business']->getKey())->first();

        $this->assertTrue(
            ($mode === BookingMode::Staffless->value && $booking?->staff_id === null)
            || ($mode === BookingMode::StaffRequired->value && $booking === null),
        );
        $this->assertSame(1, $this->countResult($results, 'created') + $this->countResult($results, 'settings_updated'));
        $this->assertSame(1, $this->countRejection($results, 'STAFF_REQUIRED')
            + $this->countRejection($results, 'BOOKING_MODE_CHANGE_BLOCKED'));
    }

    public function test_opposite_location_reschedules_lock_resources_in_a_stable_order(): void
    {
        $fixture = $this->fixture(locationCount: 2);
        $first = $this->createBooking($fixture, 0, 0, '09:00');
        $second = $this->createBooking($fixture, 1, 0, '09:00');
        $payloads = [
            $this->reschedulePayload($fixture, $first->booking, (string) $fixture['locations'][1]->getKey(), '10:00'),
            $this->reschedulePayload($fixture, $second->booking, (string) $fixture['locations'][0]->getKey(), '10:00'),
        ];

        $results = $this->runLockedRace($fixture, $payloads, [[0, 0], [1, 0]]);

        $this->assertSame(2, $this->countResult($results, 'rescheduled'));
        $this->assertSame(1, $this->countBookingsAt($fixture, (string) $fixture['locations'][0]->getKey(), '10:00'));
        $this->assertSame(1, $this->countBookingsAt($fixture, (string) $fixture['locations'][1]->getKey(), '10:00'));
    }

    public function test_independent_staffless_services_do_not_wait_on_a_business_wide_lock(): void
    {
        $fixture = $this->fixture(serviceCount: 2);
        $firstPayload = $this->createPayload($fixture, 0, 0, '09:00');
        $secondPayload = $this->createPayload($fixture, 0, 1, '09:00');
        $resources = [[0, 0]];
        $workers = [];
        $paths = $this->newBarrierPaths(2);

        try {
            DB::beginTransaction();
            app(BookingLockManager::class)->lockForServiceLocation(
                $fixture['business'],
                (string) $fixture['services'][0]->getKey(),
                (string) $fixture['locations'][0]->getKey(),
            );
            $workers = $this->startWorkers([$firstPayload, $secondPayload], $paths);
            $this->openBarrierAndWaitForStart($workers, $paths);

            $deadline = microtime(true) + 15;
            while ($workers[1]->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }

            $this->assertFalse($workers[1]->isRunning(), 'The unrelated staffless service should finish while another service lock is held.');
            $this->assertSame(0, $workers[1]->getExitCode(), $workers[1]->getErrorOutput());
            $this->assertSame('created', json_decode($workers[1]->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
            $this->assertTrue($workers[0]->isRunning(), 'The worker on the locked service should still be waiting.');
            $this->assertSame(1, $this->resourceLockWaiters());

            DB::commit();
            $workers[0]->wait();
            $this->assertSame(0, $workers[0]->getExitCode(), $workers[0]->getErrorOutput());
            $this->assertSame('created', json_decode($workers[0]->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
            $this->assertSame(2, Booking::query()->where('business_id', $fixture['business']->getKey())->count());
        } finally {
            $this->stopWorkers($workers);
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->removeBarrierPaths($paths);
        }
    }

    /** @return array{business:Business,locations:list<Location>,services:list<Service>,date:string} */
    private function fixture(int $capacity = 1, int $locationCount = 1, int $serviceCount = 1): array
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('booking_test', DB::connection()->getDatabaseName());

        $suffix = Str::lower(Str::random(12));
        $business = Business::query()->create([
            'name' => 'Locking Test '.$suffix,
            'slug' => 'locking-test-'.$suffix,
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $this->businessIds[] = (string) $business->getKey();

        $calendar = AvailabilityCalendar::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Locking Calendar '.$suffix,
            'timezone' => 'Asia/Riyadh',
        ]);
        $date = CarbonImmutable::now('Asia/Riyadh')->addDays(7)->toDateString();
        $calendar->rules()->create([
            'day_of_week' => CarbonImmutable::parse($date, 'Asia/Riyadh')->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '18:00:00',
        ]);

        $locations = [];
        for ($index = 0; $index < $locationCount; $index++) {
            $locations[] = Location::query()->create([
                'business_id' => $business->getKey(),
                'calendar_id' => $calendar->getKey(),
                'name' => "Location {$index} {$suffix}",
                'slug' => "location-{$index}-{$suffix}",
                'timezone' => 'Asia/Riyadh',
                'is_active' => true,
            ]);
        }

        $services = [];
        for ($index = 0; $index < $serviceCount; $index++) {
            $service = Service::query()->create([
                'business_id' => $business->getKey(),
                'name' => "Service {$index} {$suffix}",
                'slug' => "service-{$index}-{$suffix}",
                'duration_minutes' => 30,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'slot_interval_minutes' => 30,
                'min_notice_minutes' => 0,
                'max_advance_days' => 30,
                'price_minor' => 1000,
                'currency' => 'SAR',
                'is_active' => true,
            ]);
            $services[] = $service;
            foreach ($locations as $location) {
                $location->services()->syncWithoutDetaching([
                    $service->getKey() => ['booking_mode' => BookingMode::Staffless->value, 'capacity' => $capacity],
                ]);
            }
        }

        return compact('business', 'locations', 'services', 'date');
    }

    private function createBooking(array $fixture, int $locationIndex, int $serviceIndex, string $time, bool $hold = false): \App\Domain\Booking\DTOs\BookingCreationResult
    {
        return app(CreateBooking::class)->execute(
            $fixture['business'],
            $fixture['locations'][$locationIndex],
            $fixture['services'][$serviceIndex],
            null,
            $this->start($fixture, $time),
            ['name' => 'Seed Customer', 'phone' => '966500'.Str::random(10)],
            null,
            $hold,
        );
    }

    private function createPayload(array $fixture, int $locationIndex, int $serviceIndex, string $time, bool $hold = false): array
    {
        return [
            'operation' => 'create',
            'business_id' => $fixture['business']->getKey(),
            'location_id' => $fixture['locations'][$locationIndex]->getKey(),
            'service_id' => $fixture['services'][$serviceIndex]->getKey(),
            'start' => $this->start($fixture, $time)->format(DATE_ATOM),
            'phone' => '966500'.Str::random(10),
            'hold' => $hold,
        ];
    }

    private function reschedulePayload(array $fixture, Booking $booking, string $locationId, string $time): array
    {
        return [
            'operation' => 'reschedule',
            'business_id' => $fixture['business']->getKey(),
            'service_id' => $fixture['services'][0]->getKey(),
            'booking_id' => $booking->getKey(),
            'location_id' => $locationId,
            'start' => $this->start($fixture, $time)->format(DATE_ATOM),
        ];
    }

    private function start(array $fixture, string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($fixture['date'].'T'.$time.':00+03:00');
    }

    private function runLockedRace(array $fixture, array $payloads, array $resourceIndexes, int $holdMicros = 0): array
    {
        $workers = [];
        $paths = $this->newBarrierPaths(count($payloads));

        try {
            DB::beginTransaction();
            $resources = array_map(
                fn (array $index): array => [
                    (string) $fixture['services'][$index[1]]->getKey(),
                    (string) $fixture['locations'][$index[0]]->getKey(),
                ],
                $resourceIndexes,
            );
            app(BookingLockManager::class)->lockForServiceLocations($fixture['business'], $resources);
            $workers = $this->startWorkers($payloads, $paths);
            $this->openBarrierAndWaitForStart($workers, $paths);
            $this->waitForResourceLockWaiters(count($payloads), $workers);
            if ($holdMicros > 0) {
                usleep($holdMicros);
            }
            DB::commit();

            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            $this->stopWorkers($workers);
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->removeBarrierPaths($paths);
        }
    }

    private function newBarrierPaths(int $count): array
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'staffless-lock-race-'.Str::random(20);

        return [
            'barrier' => $base.'-go',
            'ready' => array_map(static fn (int $index): string => $base."-ready-{$index}", range(0, $count - 1)),
            'started' => array_map(static fn (int $index): string => $base."-started-{$index}", range(0, $count - 1)),
        ];
    }

    private function startWorkers(array $payloads, array $paths): array
    {
        $workers = [];
        foreach ($payloads as $index => $payload) {
            $payload['barrier'] = $paths['barrier'];
            $payload['ready'] = $paths['ready'][$index];
            $payload['started'] = $paths['started'][$index];
            $worker = new Process([PHP_BINARY, base_path('tests/Support/booking-concurrency-worker.php')], base_path());
            $worker->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
            $worker->start();
            $workers[] = $worker;
        }

        $this->waitForFiles($paths['ready'], $workers);

        return $workers;
    }

    private function openBarrierAndWaitForStart(array $workers, array $paths): void
    {
        file_put_contents($paths['barrier'], 'go');
        $this->waitForFiles($paths['started'], $workers);
    }

    private function waitForFiles(array $paths, array $workers): void
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (count(array_filter($paths, 'is_file')) === count($paths)) {
                return;
            }

            $this->assertWorkersRunning($workers);
            usleep(10000);
        }

        $this->fail('Concurrent workers did not reach their synchronization barrier.');
    }

    private function waitForResourceLockWaiters(int $expected, array $workers): void
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if ($this->resourceLockWaiters() >= $expected) {
                return;
            }

            $this->assertWorkersRunning($workers);
            usleep(10000);
        }

        $this->fail('Concurrent workers did not reach the service-location resource lock.');
    }

    private function resourceLockWaiters(): int
    {
        return (int) DB::selectOne(
            "SELECT COUNT(*) AS aggregate FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND INFO LIKE 'select * from `service_locations`%'",
        )->aggregate;
    }

    private function assertWorkersRunning(array $workers): void
    {
        foreach ($workers as $worker) {
            if (! $worker->isRunning()) {
                $this->fail($worker->getErrorOutput() ?: $worker->getOutput());
            }
        }
    }

    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    private function removeBarrierPaths(array $paths): void
    {
        foreach (array_merge([$paths['barrier']], $paths['ready'], $paths['started']) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function countStatus(array $fixture, BookingStatus $status): int
    {
        return Booking::query()
            ->where('business_id', $fixture['business']->getKey())
            ->where('status', $status->value)
            ->count();
    }

    private function countResult(array $results, string $status): int
    {
        return count(array_filter($results, static fn (array $result): bool => $result['status'] === $status));
    }

    private function countRejection(array $results, string $reason): int
    {
        return count(array_filter(
            $results,
            static fn (array $result): bool => $result['status'] === 'rejected' && $result['reason'] === $reason,
        ));
    }

    private function countBookingsAt(array $fixture, string $locationId, string $time): int
    {
        return Booking::query()
            ->where('business_id', $fixture['business']->getKey())
            ->where('location_id', $locationId)
            ->where('starts_at', $this->start($fixture, $time)->setTimezone(new DateTimeZone('UTC')))
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Held->value])
            ->count();
    }

    protected function tearDown(): void
    {
        foreach ($this->businessIds as $businessId) {
            $calendarIds = DB::table('availability_calendars')->where('business_id', $businessId)->pluck('id');
            $staffIds = DB::table('staff')->where('business_id', $businessId)->pluck('id');
            $locationIds = DB::table('locations')->where('business_id', $businessId)->pluck('id');
            $serviceIds = DB::table('services')->where('business_id', $businessId)->pluck('id');

            DB::table('booking_day_locks')->where('business_id', $businessId)->delete();
            DB::table('idempotency_keys')->where('business_id', $businessId)->delete();
            DB::table('outbox_events')->where('business_id', $businessId)->delete();
            DB::table('bookings')->where('business_id', $businessId)->delete();
            DB::table('customers')->where('business_id', $businessId)->delete();
            DB::table('staff_blocks')->where('business_id', $businessId)->delete();
            DB::table('staff_services')->whereIn('staff_id', $staffIds)->delete();
            DB::table('staff_locations')->whereIn('staff_id', $staffIds)->delete();
            DB::table('service_locations')->whereIn('service_id', $serviceIds)->delete();
            DB::table('staff')->where('business_id', $businessId)->delete();
            DB::table('locations')->where('business_id', $businessId)->delete();
            DB::table('services')->where('business_id', $businessId)->delete();
            DB::table('calendar_rules')->whereIn('calendar_id', $calendarIds)->delete();
            DB::table('calendar_overrides')->whereIn('calendar_id', $calendarIds)->delete();
            DB::table('availability_calendars')->where('business_id', $businessId)->delete();
            DB::table('businesses')->where('id', $businessId)->delete();
        }

        parent::tearDown();
    }
}
