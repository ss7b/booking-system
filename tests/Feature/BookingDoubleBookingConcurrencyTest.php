<?php

namespace Tests\Feature;

use App\Domain\Booking\Services\BookingLockManager;
use App\Models\AvailabilityCalendar;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BookingDoubleBookingConcurrencyTest extends TestCase
{
    public function test_concurrent_bookings_with_overlapping_buffers_allow_only_one_booking(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('booking_test', DB::connection()->getDatabaseName(), 'Concurrency tests must only use the isolated booking_test database.');
        $this->assertTrue(Schema::hasTable('booking_day_locks'));

        $engine = DB::selectOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['booking_day_locks'],
        )->ENGINE;
        $this->assertSame('InnoDB', $engine);

        $business = Business::query()->create([
            'name' => 'Concurrency Test Business',
            'slug' => 'concurrency-'.Str::lower(Str::random(12)),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $calendar = AvailabilityCalendar::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Concurrency Calendar',
            'timezone' => 'Asia/Riyadh',
        ]);

        $date = CarbonImmutable::now('Asia/Riyadh')->addDays(7)->toDateString();
        $calendar->rules()->create([
            'day_of_week' => CarbonImmutable::parse($date, 'Asia/Riyadh')->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);

        $location = Location::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $calendar->getKey(),
            'name' => 'Concurrency Location',
            'slug' => 'concurrency-'.Str::lower(Str::random(12)),
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);
        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Buffered Consultation',
            'slug' => 'buffered-'.Str::lower(Str::random(12)),
            'duration_minutes' => 30,
            'buffer_before_minutes' => 15,
            'buffer_after_minutes' => 15,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 30,
            'price_minor' => 10000,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $staff = Staff::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $calendar->getKey(),
            'name' => 'Concurrency Staff',
            'slug' => 'concurrency-'.Str::lower(Str::random(12)),
            'status' => 'active',
        ]);
        $staff->services()->sync([$service->getKey()]);
        $staff->locations()->sync([$location->getKey() => ['is_active' => true]]);
        $location->services()->sync([$service->getKey()]);

        $businessId = (string) $business->getKey();
        $staffId = (string) $staff->getKey();
        $locationId = (string) $location->getKey();
        $serviceId = (string) $service->getKey();
        $slotStart = new DateTimeImmutable($date.' 09:00:00', new DateTimeZone('Asia/Riyadh'));
        $overlappingStart = new DateTimeImmutable($date.' 09:30:00', new DateTimeZone('Asia/Riyadh'));
        $firstOccupiedEnd = $slotStart->modify('+45 minutes');
        $secondOccupiedStart = $overlappingStart->modify('-15 minutes');
        $this->assertLessThan($firstOccupiedEnd->getTimestamp(), $secondOccupiedStart->getTimestamp());

        $barrierPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'booking-race-'.Str::random(20);
        $workerPaths = [
            $barrierPath.'-first-ready',
            $barrierPath.'-second-ready',
        ];
        $startedPaths = [
            $barrierPath.'-first-started',
            $barrierPath.'-second-started',
        ];
        $workers = [];

        try {
            DB::beginTransaction();
            app(BookingLockManager::class)->lockForStaffRange(
                $business,
                $staff,
                CarbonImmutable::parse($date.' 08:45:00', 'Asia/Riyadh'),
                CarbonImmutable::parse($date.' 10:15:00', 'Asia/Riyadh'),
                'Asia/Riyadh',
            );

            foreach ([
                ['slot' => $slotStart, 'ready' => $workerPaths[0], 'started' => $startedPaths[0], 'phone' => '966500000'.random_int(1000, 9999)],
                ['slot' => $overlappingStart, 'ready' => $workerPaths[1], 'started' => $startedPaths[1], 'phone' => '966500000'.random_int(1000, 9999)],
            ] as $workerData) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/booking-concurrency-worker.php')], base_path());
                $worker->setInput(json_encode([
                    'business_id' => $businessId,
                    'location_id' => $locationId,
                    'service_id' => $serviceId,
                    'staff_id' => $staffId,
                    'start' => $workerData['slot']->format(DATE_ATOM),
                    'phone' => $workerData['phone'],
                    'barrier' => $barrierPath,
                    'ready' => $workerData['ready'],
                    'started' => $workerData['started'],
                ], JSON_THROW_ON_ERROR));
                $worker->start();
                $workers[] = $worker;
            }

            $this->waitForFiles($workerPaths, $workers);
            file_put_contents($barrierPath, 'go');
            $this->waitForFiles($startedPaths, $workers);
            $this->waitForLockWaiters(2, $workers);
            DB::commit();

            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            }

            $results = array_map(static fn (Process $worker): array => json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR), $workers);
            $statuses = array_column($results, 'status');
            $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'created')), json_encode($statuses));
            $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['status'] === 'slot_unavailable')), json_encode($statuses));
            $this->assertSame(1, DB::table('bookings')->where('business_id', $businessId)->where('staff_id', $staffId)->count(), json_encode($statuses));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }

            $this->deleteBusinessFixture($businessId);

            foreach (array_merge([$barrierPath], $workerPaths, $startedPaths) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function waitForFiles(array $paths, array $workers): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            if (count(array_filter($paths, 'is_file')) === count($paths)) {
                return;
            }

            foreach ($workers as $worker) {
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getErrorOutput() ?: $worker->getOutput());
                }
            }

            usleep(10000);
        }

        throw new \RuntimeException('Concurrent booking workers did not reach the synchronization barrier.');
    }

    private function waitForLockWaiters(int $expected, array $workers): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            $waiting = (int) DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND INFO LIKE 'insert ignore into `booking_day_locks`%'",
            )->aggregate;
            if ($waiting >= $expected) {
                return;
            }

            foreach ($workers as $worker) {
                if (! $worker->isRunning()) {
                    throw new \RuntimeException($worker->getErrorOutput() ?: $worker->getOutput());
                }
            }

            usleep(10000);
        }

        $transactions = DB::select('SELECT trx_state, trx_mysql_thread_id, trx_query FROM information_schema.innodb_trx');

        try {
            $processes = DB::select('SELECT ID, USER, DB, COMMAND, TIME, STATE, INFO FROM information_schema.PROCESSLIST WHERE DB = DATABASE()');
        } catch (\Throwable) {
            $processes = [];
        }

        throw new \RuntimeException('Both booking workers did not block on the held MySQL booking-day lock. Transactions: '.json_encode($transactions).'; processes: '.json_encode($processes));
    }

    private function deleteBusinessFixture(string $businessId): void
    {
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
}