<?php

namespace Tests\Feature;

use App\Domain\Booking\Services\BookingLockManager;
use App\Domain\Booking\Services\CreateBooking;
use App\Domain\Booking\Services\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Models\AvailabilityCalendar;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingConcurrencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_expires_stale_holds_before_new_booking_is_created(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $fixture = $this->makeBookingFixture($business, '2026-10-05');

        $customer = Customer::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Hold Expiry Customer',
            'phone' => '+966500000111',
            'normalized_phone' => '966500000111',
        ]);

        $stale = Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'service_id' => $fixture['service']->getKey(),
            'staff_id' => $fixture['staff']->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => 'BK-HELD-EXPIRED',
            'status' => BookingStatus::Held,
            'source' => 'public',
            'starts_at' => new DateTimeImmutable('2026-10-05T09:00:00+03:00'),
            'ends_at' => new DateTimeImmutable('2026-10-05T09:30:00+03:00'),
            'occupied_starts_at' => new DateTimeImmutable('2026-10-05T08:45:00+03:00'),
            'occupied_ends_at' => new DateTimeImmutable('2026-10-05T09:35:00+03:00'),
            'hold_expires_at' => now()->subMinute(),
            'service_name_snapshot' => $fixture['service']->name,
            'duration_snapshot' => $fixture['service']->duration_minutes,
            'price_minor_snapshot' => $fixture['service']->price_minor,
            'currency_snapshot' => $fixture['service']->currency ?? $business->default_currency,
            'access_token_hash' => 'stale-hold',
            'notes' => 'stale hold',
        ]);

        $result = app(CreateBooking::class)->execute(
            $business,
            $fixture['location'],
            $fixture['service'],
            $fixture['staff'],
            new DateTimeImmutable('2026-10-05T09:00:00+03:00'),
            ['name' => 'Fresh Customer', 'phone' => '+966500000112'],
            'Replacement after hold expiry',
            false,
            'public',
            'customer',
            'customer-1',
        );

        $this->assertSame(BookingStatus::Expired, $stale->fresh()->status);
        $this->assertNotNull($result->booking->getKey());
        $this->assertSame('confirmed', $result->booking->status->value);
    }

    public function test_it_reschedules_a_confirmed_booking_and_creates_history(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $fixture = $this->makeBookingFixture($business, '2026-10-05');

        $customer = Customer::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Reschedule Customer',
            'phone' => '+966500000222',
            'normalized_phone' => '966500000222',
        ]);

        $booking = Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'service_id' => $fixture['service']->getKey(),
            'staff_id' => $fixture['staff']->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => 'BK-RESCHEDULE-001',
            'status' => BookingStatus::Confirmed,
            'source' => 'public',
            'starts_at' => new DateTimeImmutable('2026-10-05T09:00:00+03:00'),
            'ends_at' => new DateTimeImmutable('2026-10-05T09:30:00+03:00'),
            'occupied_starts_at' => new DateTimeImmutable('2026-10-05T08:45:00+03:00'),
            'occupied_ends_at' => new DateTimeImmutable('2026-10-05T09:35:00+03:00'),
            'service_name_snapshot' => $fixture['service']->name,
            'duration_snapshot' => $fixture['service']->duration_minutes,
            'price_minor_snapshot' => $fixture['service']->price_minor,
            'currency_snapshot' => $fixture['service']->currency ?? $business->default_currency,
            'access_token_hash' => 'reschedule-hash',
            'confirmed_at' => now(),
        ]);

        $updated = app(RescheduleBooking::class)->execute(
            $business,
            $booking,
            $fixture['location'],
            $fixture['staff'],
            new DateTimeImmutable('2026-10-05T10:00:00+03:00'),
            'admin',
            'admin-1',
            'Customer requested a later slot.',
        );

        $this->assertSame(BookingStatus::Confirmed, $updated->status);
        $this->assertSame('2026-10-05T07:00:00+00:00', $updated->starts_at->format(DATE_ATOM));
        $this->assertSame('2026-10-05T07:30:00+00:00', $updated->ends_at->format(DATE_ATOM));
        $this->assertNotNull($updated->histories()->latest('created_at')->first());
    }

    public function test_it_serializes_duplicate_day_locks_for_the_same_staff_slot(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $fixture = $this->makeBookingFixture($business, '2026-10-05');

        DB::transaction(function () use ($business, $fixture): void {
            app(BookingLockManager::class)->lockForStaffRange(
                $business,
                $fixture['staff'],
                CarbonImmutable::parse('2026-10-05 08:45:00', 'Asia/Riyadh'),
                CarbonImmutable::parse('2026-10-05 09:35:00', 'Asia/Riyadh'),
                'Asia/Riyadh',
            );
        });

        $count = DB::table('booking_day_locks')
            ->where('business_id', $business->getKey())
            ->where('staff_id', $fixture['staff']->getKey())
            ->where('booking_date', '2026-10-05')
            ->count();

        $this->assertSame(1, $count);
    }

    private function makeBusiness(): Business
    {
        return Business::query()->create([
            'name' => 'Concurrency Test Business',
            'slug' => 'concurrency-' . Str::random(6),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
    }

    private function makeBookingFixture(Business $business, string $forDate): array
    {
        $calendar = AvailabilityCalendar::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Test Calendar',
            'timezone' => 'Asia/Riyadh',
        ]);

        $calendar->rules()->create([
            'day_of_week' => CarbonImmutable::parse($forDate, 'Asia/Riyadh')->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);

        $location = Location::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $calendar->getKey(),
            'name' => 'Main Location',
            'slug' => 'main-' . Str::random(6),
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);

        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'General Consultation',
            'slug' => 'general-' . Str::random(6),
            'duration_minutes' => 30,
            'buffer_before_minutes' => 15,
            'buffer_after_minutes' => 15,
            'slot_interval_minutes' => 15,
            'min_notice_minutes' => 0,
            'max_advance_days' => 30,
            'price_minor' => 15000,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $staff = Staff::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $calendar->getKey(),
            'name' => 'Sara Demo',
            'slug' => 'sara-' . Str::random(6),
            'status' => 'active',
        ]);

        $staff->services()->sync([$service->getKey()]);
        $staff->locations()->sync([$location->getKey() => ['is_active' => true]]);
        $location->services()->sync([$service->getKey()]);

        return [
            'calendar' => $calendar,
            'location' => $location,
            'service' => $service,
            'staff' => $staff,
        ];
    }

    private function markTestSkippedIfNotMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This integration test requires a real MySQL database connection.');
        }
    }
}
