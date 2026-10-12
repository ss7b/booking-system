<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\AvailabilityCalendar;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoBookingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo booking data can only be seeded in local or testing environments.');
        }

        DB::transaction(function (): void {
            $business = Business::query()->firstOrCreate(
                ['slug' => 'clinec-demo'],
                [
                    'name' => 'Clinec Demo',
                    'timezone' => 'Asia/Riyadh',
                    'default_currency' => 'SAR',
                    'is_active' => true,
                ],
            );

            $user = User::query()->firstOrCreate(
                ['email' => 'admin@example.test'],
                ['name' => 'Demo Admin', 'password' => 'password', 'email_verified_at' => now()],
            );
            $business->users()->syncWithoutDetaching([$user->getKey() => ['role' => BusinessRole::Owner->value, 'access_scope' => BranchAccessScope::All->value]]);

            $calendar = AvailabilityCalendar::query()->firstOrCreate(
                ['business_id' => $business->getKey(), 'name' => 'Main Calendar'],
                ['timezone' => 'Asia/Riyadh'],
            );

            for ($day = 0; $day <= 6; $day++) {
                $calendar->rules()->firstOrCreate([
                    'day_of_week' => $day,
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                ]);
            }

            $location = Location::query()->firstOrCreate(
                ['business_id' => $business->getKey(), 'slug' => 'main'],
                [
                    'calendar_id' => $calendar->getKey(),
                    'name' => 'Main Location',
                    'timezone' => 'Asia/Riyadh',
                    'is_active' => true,
                ],
            );

            $service = Service::query()->firstOrCreate(
                ['business_id' => $business->getKey(), 'slug' => 'general-consultation'],
                [
                    'name' => 'General Consultation',
                    'description' => 'Demo appointment service.',
                    'duration_minutes' => 30,
                    'buffer_before_minutes' => 5,
                    'buffer_after_minutes' => 5,
                    'slot_interval_minutes' => 15,
                    'min_notice_minutes' => 0,
                    'max_advance_days' => 90,
                    'price_minor' => 15000,
                    'currency' => 'SAR',
                    'is_active' => true,
                ],
            );

            $staff = Staff::query()->firstOrCreate(
                ['business_id' => $business->getKey(), 'email' => 'sara@example.test'],
                [
                    'calendar_id' => $calendar->getKey(),
                    'name' => 'Sara Demo',
                    'status' => 'active',
                ],
            );

            $staff->services()->syncWithoutDetaching([$service->getKey()]);
            $staff->locations()->syncWithoutDetaching([$location->getKey() => ['is_active' => true]]);
            $service->locations()->syncWithoutDetaching([$location->getKey()]);

            $customer = Customer::query()->firstOrCreate(
                ['business_id' => $business->getKey(), 'normalized_phone' => '966500000001'],
                ['name' => 'Demo Customer', 'phone' => '+966500000001', 'email' => 'customer@example.test'],
            );

            $start = CarbonImmutable::now('Asia/Riyadh')->addDays(2)->startOfDay()->setTime(10, 0);
            $end = $start->addMinutes(30);
            $booking = Booking::query()->firstOrCreate(
                ['reference' => 'BK-DEMO-000001'],
                [
                    'business_id' => $business->getKey(),
                    'location_id' => $location->getKey(),
                    'service_id' => $service->getKey(),
                    'staff_id' => $staff->getKey(),
                    'customer_id' => $customer->getKey(),
                    'status' => BookingStatus::Confirmed,
                    'source' => 'demo',
                    'starts_at' => $start->utc(),
                    'ends_at' => $end->utc(),
                    'occupied_starts_at' => $start->subMinutes(5)->utc(),
                    'occupied_ends_at' => $end->addMinutes(5)->utc(),
                    'service_name_snapshot' => $service->name,
                    'duration_snapshot' => $service->duration_minutes,
                    'price_minor_snapshot' => $service->price_minor,
                    'currency_snapshot' => $service->currency,
                    'confirmed_at' => now(),
                ],
            );

            BookingStatusHistory::query()->firstOrCreate(
                ['booking_id' => $booking->getKey(), 'to_status' => BookingStatus::Confirmed->value],
                ['from_status' => null, 'actor_type' => 'system', 'reason' => 'Demo fixture.'],
            );
        });

        $this->command?->info('Demo booking data is ready.');
        $this->command?->line('Admin: admin@example.test / password');
        $this->command?->line('Tenant: clinec-demo');
    }
}