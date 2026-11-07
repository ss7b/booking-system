<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffBookingContextTest extends TestCase
{
    use DatabaseMigrations;

    public function test_context_returns_a_single_eligible_location_and_service_without_private_fields(): void
    {
        $business = $this->createBusiness();
        $location = $this->createLocation($business, 'Main', 'Asia/Riyadh');
        $service = $this->createService($business, 'Consultation', 30);
        $staff = $this->createStaff($business, 'Dr. Example');
        $this->assignStaffLocation($staff, $location);
        $this->assignStaffService($staff, $service);
        $this->assignServiceLocation($service, $location);

        $this->withHeader('X-Business-Slug', $business->slug)
            ->getJson('/api/v1/staff/'.$staff->getKey().'/booking-context')
            ->assertOk()
            ->assertJsonPath('data.staff.id', $staff->getKey())
            ->assertJsonPath('data.staff.name', 'Dr. Example')
            ->assertJsonPath('data.booking_eligible', true)
            ->assertJsonPath('data.locations.0.id', $location->getKey())
            ->assertJsonPath('data.locations.0.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.locations.0.services.0.id', $service->getKey())
            ->assertJsonPath('data.locations.0.services.0.duration_minutes', 30)
            ->assertJsonMissingPath('data.staff.email')
            ->assertJsonMissingPath('data.staff.phone')
            ->assertJsonMissingPath('data.staff.metadata');
    }

    public function test_context_returns_services_according_to_their_actual_location_assignments(): void
    {
        $business = $this->createBusiness();
        $firstLocation = $this->createLocation($business, 'First');
        $secondLocation = $this->createLocation($business, 'Second');
        $firstService = $this->createService($business, 'First service', 30);
        $secondService = $this->createService($business, 'Second service', 45);
        $staff = $this->createStaff($business);

        foreach ([$firstLocation, $secondLocation] as $location) {
            $this->assignStaffLocation($staff, $location);
        }
        foreach ([$firstService, $secondService] as $service) {
            $this->assignStaffService($staff, $service);
        }
        $this->assignServiceLocation($firstService, $firstLocation);
        $this->assignServiceLocation($secondService, $secondLocation);

        $this->withHeader('X-Business-Slug', $business->slug)
            ->getJson('/api/v1/staff/'.$staff->getKey().'/booking-context')
            ->assertOk()
            ->assertJsonPath('data.booking_eligible', true)
            ->assertJsonCount(2, 'data.locations')
            ->assertJsonPath('data.locations.0.services.0.id', $firstService->getKey())
            ->assertJsonPath('data.locations.1.services.0.id', $secondService->getKey());
    }

    public function test_context_excludes_inactive_and_unmatched_location_service_combinations(): void
    {
        $business = $this->createBusiness();
        $inactiveLocation = $this->createLocation($business, 'Inactive location', null, false);
        $inactiveStaffLinkLocation = $this->createLocation($business, 'Inactive staff link');
        $serviceLinkMismatchLocation = $this->createLocation($business, 'Service link mismatch');
        $inactiveServiceLocation = $this->createLocation($business, 'Inactive service');
        $inactiveLocationService = $this->createService($business, 'Service at inactive location');
        $unlinkedService = $this->createService($business, 'Service not at location');
        $inactiveLinkService = $this->createService($business, 'Service with inactive staff link');
        $inactiveService = $this->createService($business, 'Inactive service assignment', 30, false);
        $staff = $this->createStaff($business);
        $otherBusiness = $this->createBusiness();
        $otherLocation = $this->createLocation($otherBusiness, 'Other business location');
        $otherService = $this->createService($otherBusiness, 'Other business service');

        $this->assignStaffLocation($staff, $inactiveLocation);
        $this->assignStaffLocation($staff, $inactiveStaffLinkLocation, false);
        $this->assignStaffLocation($staff, $serviceLinkMismatchLocation);
        $this->assignStaffLocation($staff, $inactiveServiceLocation);
        $this->assignStaffLocation($staff, $otherLocation);
        $this->assignStaffService($staff, $inactiveLocationService);
        $this->assignStaffService($staff, $unlinkedService);
        $this->assignStaffService($staff, $inactiveLinkService);
        $this->assignStaffService($staff, $inactiveService);
        $this->assignStaffService($staff, $otherService);
        $this->assignServiceLocation($inactiveLocationService, $inactiveLocation);
        $this->assignServiceLocation($inactiveLinkService, $inactiveStaffLinkLocation);
        $this->assignServiceLocation($inactiveService, $inactiveServiceLocation);
        $this->assignServiceLocation($otherService, $otherLocation);

        $this->withHeader('X-Business-Slug', $business->slug)
            ->getJson('/api/v1/staff/'.$staff->getKey().'/booking-context')
            ->assertOk()
            ->assertJsonPath('data.booking_eligible', false)
            ->assertJsonCount(0, 'data.locations');
    }

    public function test_context_hides_missing_inactive_and_other_business_staff_and_returns_empty_for_unassigned_staff(): void
    {
        $business = $this->createBusiness();
        $unassignedStaff = $this->createStaff($business);
        $inactiveStaff = $this->createStaff($business, 'Inactive staff', 'inactive');
        $otherBusinessStaff = $this->createStaff($this->createBusiness());

        $this->withHeader('X-Business-Slug', $business->slug)
            ->getJson('/api/v1/staff/'.$unassignedStaff->getKey().'/booking-context')
            ->assertOk()
            ->assertJsonPath('data.booking_eligible', false)
            ->assertJsonCount(0, 'data.locations');

        $this->withHeader('X-Business-Slug', $business->slug)
            ->getJson('/api/v1/staff/'.$inactiveStaff->getKey().'/booking-context')
            ->assertNotFound();

        $this->withHeader('X-Business-Slug', $business->slug)
            ->getJson('/api/v1/staff/'.$otherBusinessStaff->getKey().'/booking-context')
            ->assertNotFound();

        $this->withHeader('X-Business-Slug', $business->slug)
            ->getJson('/api/v1/staff/01J00000000000000000000000/booking-context')
            ->assertNotFound();
    }

    public function test_existing_public_endpoints_keep_their_current_response_contracts(): void
    {
        $business = $this->createBusiness();
        $location = $this->createLocation($business, 'Main');
        $service = $this->createService($business, 'Consultation');
        $staff = $this->createStaff($business);
        $this->assignStaffLocation($staff, $location);
        $this->assignStaffService($staff, $service);
        $this->assignServiceLocation($service, $location);
        $headers = ['X-Business-Slug' => $business->slug];

        $this->withHeaders($headers)->getJson('/api/v1/locations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $location->getKey());

        $this->withHeaders($headers)->getJson('/api/v1/services?location_id='.$location->getKey())
            ->assertOk()
            ->assertJsonPath('data.0.id', $service->getKey())
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($headers)->getJson('/api/v1/staff?service_id='.$service->getKey().'&location_id='.$location->getKey())
            ->assertOk()
            ->assertJsonPath('data.0.id', $staff->getKey());
    }

    private function createBusiness(): Business
    {
        return Business::query()->create([
            'name' => 'Booking Context Business',
            'slug' => 'booking-context-'.fake()->unique()->numerify('#####'),
            'timezone' => 'UTC',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
    }

    private function createLocation(Business $business, string $name, ?string $timezone = 'UTC', bool $active = true): Location
    {
        return Location::query()->create([
            'business_id' => $business->getKey(),
            'name' => $name,
            'slug' => str($name)->slug().'-'.fake()->unique()->numerify('#####'),
            'timezone' => $timezone,
            'is_active' => $active,
        ]);
    }

    private function createService(Business $business, string $name, int $duration = 30, bool $active = true): Service
    {
        return Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => $name,
            'slug' => str($name)->slug().'-'.fake()->unique()->numerify('#####'),
            'duration_minutes' => $duration,
            'slot_interval_minutes' => $duration,
            'is_active' => $active,
        ]);
    }

    private function createStaff(Business $business, string $name = 'Dr. Test', string $status = 'active'): Staff
    {
        return Staff::query()->create([
            'business_id' => $business->getKey(),
            'name' => $name,
            'email' => 'private@example.test',
            'phone' => '0500000000',
            'metadata' => ['private' => true],
            'status' => $status,
        ]);
    }

    private function assignStaffLocation(Staff $staff, Location $location, bool $active = true): void
    {
        DB::table('staff_locations')->insert([
            'staff_id' => $staff->getKey(),
            'location_id' => $location->getKey(),
            'is_active' => $active,
        ]);
    }

    private function assignStaffService(Staff $staff, Service $service): void
    {
        DB::table('staff_services')->insert([
            'staff_id' => $staff->getKey(),
            'service_id' => $service->getKey(),
        ]);
    }

    private function assignServiceLocation(Service $service, Location $location): void
    {
        DB::table('service_locations')->insert([
            'service_id' => $service->getKey(),
            'location_id' => $location->getKey(),
        ]);
    }
}
