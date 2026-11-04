<?php

namespace Tests\Feature;

use App\Domain\Booking\Services\BookingLockManager;
use App\Domain\Booking\Services\BookingConflictChecker;
use App\Domain\Booking\Services\CreateBooking;
use App\Domain\Booking\Services\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Models\AvailabilityCalendar;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingConcurrencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reuses_customers_by_phone_or_email_within_their_business(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $date = $this->futureDate();
        $fixture = $this->makeBookingFixture($business, $date);
        $matchDates = [];
        for ($offset = 1; $offset <= 6; $offset++) {
            $matchDate = CarbonImmutable::parse($date, 'Asia/Riyadh')->addDays($offset);
            $matchDates[$offset] = $matchDate->toDateString();
            $fixture['calendar']->rules()->create([
                'day_of_week' => $matchDate->dayOfWeek,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
            ]);
        }
        $createBooking = function (
            string $time,
            array $customer,
            bool $hold = false,
            ?string $bookingDate = null,
        ) use ($business, $fixture, $date) {
            return app(CreateBooking::class)->execute(
                $business,
                $fixture['location'],
                $fixture['service'],
                $fixture['staff'],
                new DateTimeImmutable(($bookingDate ?? $date).'T'.$time.':00+03:00'),
                $customer,
                null,
                $hold,
            );
        };

        $original = $createBooking('09:00', [
            'name' => 'Original Customer',
            'phone' => '0501234567',
            'email' => 'Test@Example.com',
        ]);
        $customer = $original->booking->customer;
        $customer->email = 'Test@Example.com';
        $customer->save();

        $phoneMatch = $createBooking('10:30', [
            'name' => 'Changed Name',
            'phone' => '050-123-4567',
            'email' => 'other@example.com',
        ], false, $matchDates[1]);
        $this->assertSame($customer->getKey(), $phoneMatch->booking->customer_id);
        $this->assertSame('Original Customer', $customer->fresh()->name);
        $this->assertSame('Test@Example.com', $customer->fresh()->email);
        $this->assertSame('other@example.com', $phoneMatch->booking->metadata['customer_contact']['email']);

        $emailMatch = $createBooking('12:00', [
            'name' => 'Another Name',
            'phone' => '0507654321',
            'email' => ' TEST@EXAMPLE.COM ',
        ], false, $matchDates[2]);
        $this->assertSame($customer->getKey(), $emailMatch->booking->customer_id);

        $bothMatch = $createBooking('13:30', [
            'name' => 'Final Name',
            'phone' => '050 123 4567',
            'email' => 'Test@Example.com',
        ], false, $matchDates[3]);
        $this->assertSame($customer->getKey(), $bothMatch->booking->customer_id);

        $newCustomer = $createBooking('14:45', [
            'name' => 'New Customer',
            'phone' => '0509999999',
            'email' => null,
        ], false, $matchDates[4]);
        $this->assertNotSame($customer->getKey(), $newCustomer->booking->customer_id);

        $hold = $createBooking('16:15', [
            'name' => 'Held Customer',
            'phone' => '0501234567',
            'email' => 'different@example.com',
        ], true, $matchDates[5]);
        $this->assertSame($customer->getKey(), $hold->booking->customer_id);
        $this->assertSame(BookingStatus::Held, $hold->booking->status);

        Customer::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Phone Match',
            'phone' => '0501111111',
            'normalized_phone' => '0501111111',
            'email' => 'first@example.com',
        ]);
        Customer::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Email Match',
            'phone' => '0502222222',
            'normalized_phone' => '0502222222',
            'email' => 'second@example.com',
        ]);

        try {
            $createBooking('17:15', [
                'name' => 'Ambiguous Customer',
                'phone' => '0501111111',
                'email' => 'second@example.com',
            ]);
            $this->fail('Conflicting phone and email matches should be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('CUSTOMER_CONTACT_CONFLICT', $exception->getMessage());
        }

        $otherBusiness = $this->makeBusiness();
        $otherFixture = $this->makeBookingFixture($otherBusiness, $date);
        $otherBusinessBooking = app(CreateBooking::class)->execute(
            $otherBusiness,
            $otherFixture['location'],
            $otherFixture['service'],
            $otherFixture['staff'],
            new DateTimeImmutable($date.'T09:00:00+03:00'),
            ['name' => 'Other Business Customer', 'phone' => '0501234567'],
            null,
            false,
        );
        $this->assertNotSame($customer->getKey(), $otherBusinessBooking->booking->customer_id);
        $this->assertSame(4, $business->customers()->count());
    }

    public function test_it_expires_stale_holds_before_new_booking_is_created(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $date = $this->futureDate();
        $fixture = $this->makeBookingFixture($business, $date);

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
            'starts_at' => new DateTimeImmutable($date.'T09:00:00+03:00'),
            'ends_at' => new DateTimeImmutable($date.'T09:30:00+03:00'),
            'occupied_starts_at' => new DateTimeImmutable($date.'T08:45:00+03:00'),
            'occupied_ends_at' => new DateTimeImmutable($date.'T09:35:00+03:00'),
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
            new DateTimeImmutable($date.'T09:00:00+03:00'),
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

    public function test_it_blocks_customer_overlaps_across_staff_and_locations_but_allows_other_times(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $date = $this->futureDate();
        $fixture = $this->makeBookingFixture($business, $date);
        $secondLocation = Location::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $fixture['calendar']->getKey(),
            'name' => 'Second Location',
            'slug' => 'second-'.Str::random(6),
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);
        $secondLocation->services()->sync([$fixture['service']->getKey()]);
        $fixture['service']->locations()->syncWithoutDetaching([$secondLocation->getKey()]);
        $otherService = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Different Consultation',
            'slug' => 'different-'.Str::random(6),
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'slot_interval_minutes' => 15,
            'min_notice_minutes' => 0,
            'max_advance_days' => 30,
            'price_minor' => 12000,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $otherService->locations()->sync([$fixture['location']->getKey(), $secondLocation->getKey()]);
        $fixture['staff']->services()->syncWithoutDetaching([$otherService->getKey()]);
        $secondStaff = Staff::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $fixture['calendar']->getKey(),
            'name' => 'Second Staff',
            'slug' => 'second-'.Str::random(6),
            'status' => 'active',
        ]);
        $secondStaff->services()->sync([$fixture['service']->getKey(), $otherService->getKey()]);
        $secondStaff->locations()->sync([$secondLocation->getKey() => ['is_active' => true]]);

        $createBooking = function (
            string $time,
            Staff $staff,
            Location $location,
            bool $hold = false,
            ?Service $service = null,
        ) use ($business, $fixture, $date) {
            return app(CreateBooking::class)->execute(
                $business,
                $location,
                $service ?? $fixture['service'],
                $staff,
                new DateTimeImmutable($date.'T'.$time.':00+03:00'),
                ['name' => 'Overlap Customer', 'phone' => '+966500000333'],
                null,
                $hold,
            );
        };

        $first = $createBooking('10:00', $fixture['staff'], $fixture['location']);
        $adjacent = $createBooking('10:30', $secondStaff, $secondLocation, false, $otherService);
        $customer = $first->booking->customer;

        $this->assertSame($first->booking->customer_id, $adjacent->booking->customer_id);

        try {
            $createBooking('10:15', $secondStaff, $secondLocation, false, $otherService);
            $this->fail('An overlapping booking for the same customer should be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('CUSTOMER_BOOKING_OVERLAP', $exception->getMessage());
        }

        $holdStart = new DateTimeImmutable($date.'T16:15:00+03:00');
        $holdEnd = $holdStart->modify('+30 minutes');
        $hold = Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $secondLocation->getKey(),
            'service_id' => $fixture['service']->getKey(),
            'staff_id' => $secondStaff->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => 'BK-CUSTOMER-OVERLAP-HOLD',
            'status' => BookingStatus::Held,
            'source' => 'public',
            'starts_at' => $holdStart->setTimezone(new DateTimeZone('UTC')),
            'ends_at' => $holdEnd->setTimezone(new DateTimeZone('UTC')),
            'occupied_starts_at' => $holdStart->modify('-15 minutes')->setTimezone(new DateTimeZone('UTC')),
            'occupied_ends_at' => $holdEnd->modify('+5 minutes')->setTimezone(new DateTimeZone('UTC')),
            'hold_expires_at' => now()->addMinutes(5),
            'service_name_snapshot' => $fixture['service']->name,
            'duration_snapshot' => $fixture['service']->duration_minutes,
            'price_minor_snapshot' => $fixture['service']->price_minor,
            'currency_snapshot' => $fixture['service']->currency ?? $business->default_currency,
            'access_token_hash' => 'customer-overlap-hold',
        ]);
        $conflicts = app(BookingConflictChecker::class);
        $overlapStart = new DateTimeImmutable($date.'T16:20:00+03:00');
        $overlapEnd = new DateTimeImmutable($date.'T16:25:00+03:00');

        $this->assertTrue(DB::transaction(fn (): bool => $conflicts->hasCustomerConflict(
            $business,
            (string) $customer->getKey(),
            $overlapStart,
            $overlapEnd,
        )));

        $hold->hold_expires_at = now()->subMinute();
        $hold->save();
        $this->assertFalse(DB::transaction(fn (): bool => $conflicts->hasCustomerConflict(
            $business,
            (string) $customer->getKey(),
            $overlapStart,
            $overlapEnd,
        )));

        $adjacent->booking->status = BookingStatus::CheckedIn;
        $adjacent->booking->save();
        $checkedInStart = new DateTimeImmutable($date.'T10:40:00+03:00');
        $checkedInEnd = new DateTimeImmutable($date.'T10:45:00+03:00');
        $this->assertTrue(DB::transaction(fn (): bool => $conflicts->hasCustomerConflict(
            $business,
            (string) $customer->getKey(),
            $checkedInStart,
            $checkedInEnd,
        )));

        $adjacent->booking->status = BookingStatus::Completed;
        $adjacent->booking->save();
        $this->assertFalse(DB::transaction(fn (): bool => $conflicts->hasCustomerConflict(
            $business,
            (string) $customer->getKey(),
            $checkedInStart,
            $checkedInEnd,
        )));

        $first->booking->status = BookingStatus::Cancelled;
        $first->booking->save();
        $cancelledStart = new DateTimeImmutable($date.'T10:10:00+03:00');
        $cancelledEnd = new DateTimeImmutable($date.'T10:20:00+03:00');
        $this->assertFalse(DB::transaction(fn (): bool => $conflicts->hasCustomerConflict(
            $business,
            (string) $customer->getKey(),
            $cancelledStart,
            $cancelledEnd,
        )));
    }

    public function test_it_prevents_a_second_active_booking_for_the_same_service_on_the_local_date(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $date = $this->futureDate();
        $fixture = $this->makeBookingFixture($business, $date);
        $secondDate = CarbonImmutable::parse($date, 'Asia/Riyadh')->addDay()->toDateString();
        $thirdDate = CarbonImmutable::parse($date, 'Asia/Riyadh')->addDays(2)->toDateString();
        foreach ([$secondDate, $thirdDate] as $availableDate) {
            $fixture['calendar']->rules()->create([
                'day_of_week' => CarbonImmutable::parse($availableDate, 'Asia/Riyadh')->dayOfWeek,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
            ]);
        }

        $secondLocation = Location::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $fixture['calendar']->getKey(),
            'name' => 'Daily Limit Location',
            'slug' => 'daily-limit-'.Str::random(6),
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);
        $secondLocation->services()->sync([$fixture['service']->getKey()]);
        $fixture['service']->locations()->syncWithoutDetaching([$secondLocation->getKey()]);
        $secondStaff = Staff::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $fixture['calendar']->getKey(),
            'name' => 'Daily Limit Staff',
            'slug' => 'daily-limit-'.Str::random(6),
            'status' => 'active',
        ]);
        $secondStaff->services()->sync([$fixture['service']->getKey()]);
        $secondStaff->locations()->sync([$secondLocation->getKey() => ['is_active' => true]]);

        $createBooking = function (
            string $bookingDate,
            string $time,
            Staff $staff,
            Location $location,
            ?Service $service = null,
        ) use ($business, $fixture) {
            return app(CreateBooking::class)->execute(
                $business,
                $location,
                $service ?? $fixture['service'],
                $staff,
                new DateTimeImmutable($bookingDate.'T'.$time.':00+03:00'),
                ['name' => 'Daily Limit Customer', 'phone' => '+966500000555'],
                null,
                false,
            );
        };

        $first = $createBooking($date, '10:00', $fixture['staff'], $fixture['location']);

        try {
            $createBooking($date, '14:00', $secondStaff, $secondLocation);
            $this->fail('A second same-service booking on the same local date should be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('CUSTOMER_SERVICE_DAILY_LIMIT', $exception->getMessage());
        }

        $otherService = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Another Daily Service',
            'slug' => 'another-daily-'.Str::random(6),
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'slot_interval_minutes' => 15,
            'min_notice_minutes' => 0,
            'max_advance_days' => 30,
            'price_minor' => 12000,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $otherService->locations()->sync([$secondLocation->getKey()]);
        $secondStaff->services()->syncWithoutDetaching([$otherService->getKey()]);
        $differentService = $createBooking($date, '10:30', $secondStaff, $secondLocation, $otherService);
        $nextDay = $createBooking($secondDate, '10:00', $secondStaff, $secondLocation);

        $this->assertNotSame($first->booking->service_id, $differentService->booking->service_id);
        $this->assertSame($first->booking->customer_id, $nextDay->booking->customer_id);

        $first->booking->status = BookingStatus::Cancelled;
        $first->booking->save();
        $afterCancellation = $createBooking($date, '14:00', $secondStaff, $secondLocation);
        $this->assertNotNull($afterCancellation->booking->getKey());

        $holdCustomer = Customer::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Daily Hold Customer',
            'phone' => '+966500000556',
            'normalized_phone' => '966500000556',
        ]);
        $dailyHoldStart = new DateTimeImmutable($thirdDate.'T00:15:00+03:00');
        $dailyHoldEnd = $dailyHoldStart->modify('+30 minutes');
        $hold = Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'service_id' => $fixture['service']->getKey(),
            'staff_id' => $fixture['staff']->getKey(),
            'customer_id' => $holdCustomer->getKey(),
            'reference' => 'BK-DAILY-LIMIT-HOLD',
            'status' => BookingStatus::Held,
            'source' => 'public',
            'starts_at' => $dailyHoldStart->setTimezone(new DateTimeZone('UTC')),
            'ends_at' => $dailyHoldEnd->setTimezone(new DateTimeZone('UTC')),
            'occupied_starts_at' => $dailyHoldStart->setTime(0, 0)->setTimezone(new DateTimeZone('UTC')),
            'occupied_ends_at' => $dailyHoldEnd->modify('+5 minutes')->setTimezone(new DateTimeZone('UTC')),
            'hold_expires_at' => now()->addMinutes(5),
            'service_name_snapshot' => $fixture['service']->name,
            'duration_snapshot' => $fixture['service']->duration_minutes,
            'price_minor_snapshot' => $fixture['service']->price_minor,
            'currency_snapshot' => $fixture['service']->currency ?? $business->default_currency,
            'access_token_hash' => 'daily-limit-hold',
        ]);
        $conflicts = app(BookingConflictChecker::class);
        $localStart = new DateTimeImmutable($thirdDate.'T10:00:00+03:00');
        $hasServiceForDate = fn (): bool => DB::transaction(fn (): bool => $conflicts->hasCustomerServiceOnDate(
            $business,
            (string) $holdCustomer->getKey(),
            (string) $fixture['service']->getKey(),
            $localStart,
        ));

        $this->assertTrue($hasServiceForDate());
        $hold->hold_expires_at = now()->subMinute();
        $hold->save();
        $this->assertFalse($hasServiceForDate());
        foreach ([BookingStatus::Completed, BookingStatus::Cancelled, BookingStatus::NoShow, BookingStatus::Expired] as $inactiveStatus) {
            $hold->status = $inactiveStatus;
            $hold->hold_expires_at = now()->addMinutes(5);
            $hold->save();
            $this->assertFalse($hasServiceForDate());
        }

        $utcBoundaryCustomer = Customer::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'UTC Boundary Customer',
            'phone' => '+966500000557',
            'normalized_phone' => '966500000557',
        ]);
        $boundaryStart = new DateTimeImmutable($thirdDate.'T00:15:00+03:00');
        $boundaryEnd = $boundaryStart->modify('+30 minutes');
        Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'service_id' => $fixture['service']->getKey(),
            'staff_id' => $fixture['staff']->getKey(),
            'customer_id' => $utcBoundaryCustomer->getKey(),
            'reference' => 'BK-LOCAL-DATE-BOUNDARY',
            'status' => BookingStatus::Confirmed,
            'source' => 'public',
            'starts_at' => $boundaryStart->setTimezone(new DateTimeZone('UTC')),
            'ends_at' => $boundaryEnd->setTimezone(new DateTimeZone('UTC')),
            'occupied_starts_at' => $boundaryStart->setTimezone(new DateTimeZone('UTC')),
            'occupied_ends_at' => $boundaryEnd->setTimezone(new DateTimeZone('UTC')),
            'service_name_snapshot' => $fixture['service']->name,
            'duration_snapshot' => $fixture['service']->duration_minutes,
            'price_minor_snapshot' => $fixture['service']->price_minor,
            'currency_snapshot' => $fixture['service']->currency ?? $business->default_currency,
            'access_token_hash' => 'local-date-boundary',
        ]);
        $this->assertTrue(DB::transaction(fn (): bool => $conflicts->hasCustomerServiceOnDate(
            $business,
            (string) $utcBoundaryCustomer->getKey(),
            (string) $fixture['service']->getKey(),
            new DateTimeImmutable($thirdDate.'T10:00:00+03:00'),
        )));
    }

    public function test_rescheduling_rejects_customer_overlap_and_ignores_the_booking_being_moved(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $date = $this->futureDate();
        $fixture = $this->makeBookingFixture($business, $date);
        $otherService = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Reschedule Other Service',
            'slug' => 'reschedule-other-'.Str::random(6),
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'slot_interval_minutes' => 15,
            'min_notice_minutes' => 0,
            'max_advance_days' => 30,
            'price_minor' => 12000,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $otherService->locations()->sync([$fixture['location']->getKey()]);
        $fixture['staff']->services()->syncWithoutDetaching([$otherService->getKey()]);
        $createBooking = function (string $time, ?Service $service = null) use ($business, $fixture, $date) {
            return app(CreateBooking::class)->execute(
                $business,
                $fixture['location'],
                $service ?? $fixture['service'],
                $fixture['staff'],
                new DateTimeImmutable($date.'T'.$time.':00+03:00'),
                ['name' => 'Reschedule Overlap Customer', 'phone' => '+966500000444'],
                null,
                false,
            );
        };
        $bookingToMove = $createBooking('10:00')->booking;
        $existingBooking = $createBooking('14:00', $otherService)->booking;

        $unchanged = app(RescheduleBooking::class)->execute(
            $business,
            $bookingToMove,
            $fixture['location'],
            $fixture['staff'],
            new DateTimeImmutable($date.'T10:00:00+03:00'),
            'admin',
        );
        $this->assertSame($bookingToMove->getKey(), $unchanged->getKey());

        try {
            app(RescheduleBooking::class)->execute(
                $business,
                $bookingToMove,
                $fixture['location'],
                $fixture['staff'],
                new DateTimeImmutable($date.'T14:15:00+03:00'),
                'admin',
            );
            $this->fail('Rescheduling into another customer booking should be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('CUSTOMER_BOOKING_OVERLAP', $exception->getMessage());
        }

        $this->assertSame(
            (new DateTimeImmutable($date.'T10:00:00+03:00'))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            $bookingToMove->fresh()->starts_at->format(DATE_ATOM),
        );
        $this->assertNotNull($existingBooking->fresh());
    }

    public function test_rescheduling_the_current_booking_is_allowed_but_another_same_service_booking_on_target_date_is_not(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $date = $this->futureDate();
        $fixture = $this->makeBookingFixture($business, $date);
        $nextDate = CarbonImmutable::parse($date, 'Asia/Riyadh')->addDay()->toDateString();
        $fixture['calendar']->rules()->create([
            'day_of_week' => CarbonImmutable::parse($nextDate, 'Asia/Riyadh')->dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
        ]);
        $createBooking = function (string $bookingDate, string $time) use ($business, $fixture) {
            return app(CreateBooking::class)->execute(
                $business,
                $fixture['location'],
                $fixture['service'],
                $fixture['staff'],
                new DateTimeImmutable($bookingDate.'T'.$time.':00+03:00'),
                ['name' => 'Daily Reschedule Customer', 'phone' => '+966500000558'],
                null,
                false,
            );
        };
        $bookingToMove = $createBooking($date, '10:00')->booking;
        $existingOnNextDate = $createBooking($nextDate, '14:00')->booking;

        $movedWithinDate = app(RescheduleBooking::class)->execute(
            $business,
            $bookingToMove,
            $fixture['location'],
            $fixture['staff'],
            new DateTimeImmutable($date.'T11:00:00+03:00'),
            'admin',
        );
        $this->assertSame(
            (new DateTimeImmutable($date.'T11:00:00+03:00'))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            $movedWithinDate->starts_at->format(DATE_ATOM),
        );

        try {
            app(RescheduleBooking::class)->execute(
                $business,
                $bookingToMove,
                $fixture['location'],
                $fixture['staff'],
                new DateTimeImmutable($nextDate.'T10:00:00+03:00'),
                'admin',
            );
            $this->fail('Rescheduling onto a date with another active booking for the same service should be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('CUSTOMER_SERVICE_DAILY_LIMIT', $exception->getMessage());
        }

        $this->assertSame(
            (new DateTimeImmutable($date.'T11:00:00+03:00'))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            $bookingToMove->fresh()->starts_at->format(DATE_ATOM),
        );
        $this->assertNotNull($existingOnNextDate->fresh());
    }

    public function test_it_reschedules_a_confirmed_booking_and_creates_history(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $date = $this->futureDate();
        $fixture = $this->makeBookingFixture($business, $date);
        $oldStaffUser = User::factory()->create();
        $newStaffUser = User::factory()->create();
        $business->users()->attach($oldStaffUser, ['role' => 'staff', 'access_scope' => 'all']);
        $business->users()->attach($newStaffUser, ['role' => 'staff', 'access_scope' => 'all']);
        $fixture['staff']->user_id = $oldStaffUser->getKey();
        $fixture['staff']->save();
        $newStaff = Staff::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $fixture['calendar']->getKey(),
            'name' => 'New Assigned Staff',
            'status' => 'active',
            'user_id' => $newStaffUser->getKey(),
        ]);
        $newStaff->services()->sync([$fixture['service']->getKey()]);
        $newStaff->locations()->sync([$fixture['location']->getKey() => ['is_active' => true]]);

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
            'starts_at' => new DateTimeImmutable($date.'T09:00:00+03:00'),
            'ends_at' => new DateTimeImmutable($date.'T09:30:00+03:00'),
            'occupied_starts_at' => new DateTimeImmutable($date.'T08:45:00+03:00'),
            'occupied_ends_at' => new DateTimeImmutable($date.'T09:35:00+03:00'),
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
            $newStaff,
            new DateTimeImmutable($date.'T10:00:00+03:00'),
            'admin',
            'admin-1',
            'Customer requested a later slot.',
        );

        $this->assertSame(BookingStatus::Confirmed, $updated->status);
        $expectedStart = (new DateTimeImmutable($date.'T10:00:00+03:00'))->setTimezone(new DateTimeZone('UTC'));
        $this->assertSame($expectedStart->format(DATE_ATOM), $updated->starts_at->format(DATE_ATOM));
        $this->assertSame($expectedStart->modify('+30 minutes')->format(DATE_ATOM), $updated->ends_at->format(DATE_ATOM));
        $this->assertNotNull($updated->histories()->latest('created_at')->first());

        $event = OutboxEvent::query()
            ->where('event_type', 'BookingRescheduled')
            ->where('aggregate_id', $booking->getKey())
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame($oldStaffUser->getKey(), $event->payload['old_staff_user_id']);
        $this->assertSame($newStaffUser->getKey(), $event->payload['new_staff_user_id']);
    }

    public function test_it_serializes_duplicate_day_locks_for_the_same_staff_slot(): void
    {
        $this->markTestSkippedIfNotMysql();

        $business = $this->makeBusiness();
        $date = $this->futureDate();
        $fixture = $this->makeBookingFixture($business, $date);

        DB::transaction(function () use ($business, $fixture, $date): void {
            app(BookingLockManager::class)->lockForStaffRange(
                $business,
                $fixture['staff'],
                CarbonImmutable::parse($date.' 08:45:00', 'Asia/Riyadh'),
                CarbonImmutable::parse($date.' 09:35:00', 'Asia/Riyadh'),
                'Asia/Riyadh',
            );
        });

        $count = DB::table('booking_day_locks')
            ->where('business_id', $business->getKey())
            ->where('staff_id', $fixture['staff']->getKey())
            ->where('booking_date', $date)
            ->count();

        $this->assertSame(1, $count);
    }

    private function makeBusiness(): Business
    {
        return Business::query()->create([
            'name' => 'Concurrency Test Business',
            'slug' => 'concurrency-'.Str::random(6),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
    }

    private function futureDate(): string
    {
        return CarbonImmutable::now('Asia/Riyadh')->addDays(7)->toDateString();
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
            'slug' => 'main-'.Str::random(6),
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);

        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'General Consultation',
            'slug' => 'general-'.Str::random(6),
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
            'slug' => 'sara-'.Str::random(6),
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
