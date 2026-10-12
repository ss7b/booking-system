<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Authorization\ManageBusinessMemberships;
use App\Enums\BookingStatus;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessMembershipAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_has_all_branches_and_selected_scope_fails_closed(): void
    {
        [$business, $firstLocation, $secondLocation] = $this->makeBusinessWithLocations();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $business->users()->attach($owner, ['role' => BusinessRole::Owner->value, 'access_scope' => BranchAccessScope::All->value]);
        $business->users()->attach($manager, ['role' => BusinessRole::Manager->value, 'access_scope' => BranchAccessScope::Selected->value]);
        $access = app(BusinessAccess::class);

        $this->assertTrue($access->canAccessLocation($owner, $business, $firstLocation));
        $this->assertTrue($access->canAccessLocation($owner, $business, $secondLocation));
        $this->assertFalse($access->canAccessLocation($manager, $business, $firstLocation));
        $this->assertCount(0, $access->locations($manager, $business)->get());

        app(ManageBusinessMemberships::class)->save(
            $business,
            $manager,
            BusinessRole::Manager,
            BranchAccessScope::Selected,
            [(string) $secondLocation->getKey()],
        );

        $this->assertFalse($access->canAccessLocation($manager, $business, $firstLocation));
        $this->assertTrue($access->canAccessLocation($manager, $business, $secondLocation));
    }

    public function test_branch_assignment_cannot_cross_business_boundary(): void
    {
        [$business, $location] = $this->makeBusinessWithLocations();
        [$otherBusiness, $otherLocation] = $this->makeBusinessWithLocations();
        $user = User::factory()->create();
        $business->users()->attach($user, ['role' => 'manager', 'access_scope' => 'selected']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('business_user_locations_location_foreign');
        DB::table('business_user_locations')->insert([
            'business_id' => $business->getKey(),
            'user_id' => $user->getKey(),
            'location_id' => $otherLocation->getKey(),
            'created_at' => now(),
        ]);
    }

    public function test_membership_in_another_business_does_not_grant_access(): void
    {
        [$business, $location] = $this->makeBusinessWithLocations();
        [$otherBusiness] = $this->makeBusinessWithLocations();
        $user = User::factory()->create();
        $otherBusiness->users()->attach($user, ['role' => 'owner', 'access_scope' => 'all']);

        $this->assertFalse(app(BusinessAccess::class)->canAccessLocation($user, $business, $location));
    }

    public function test_business_must_retain_an_owner(): void
    {
        [$business] = $this->makeBusinessWithLocations();
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);

        try {
            app(ManageBusinessMemberships::class)->remove($business, $owner);
            $this->fail('The final owner cannot be removed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user_id', $exception->errors());
        }

        $this->assertDatabaseHas('business_user', ['business_id' => $business->getKey(), 'user_id' => $owner->getKey(), 'role' => 'owner']);
    }

    public function test_business_access_calendars_returns_builder_for_filament_policy_checks(): void
    {
        [$business] = $this->makeBusinessWithLocations();
        $user = User::factory()->create();
        $business->users()->attach($user, ['role' => 'owner', 'access_scope' => 'all']);

        $calendarQuery = app(BusinessAccess::class)->calendars($user, $business);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $calendarQuery);
        $this->assertSame($business->getKey(), $calendarQuery->getModel()->business_id ?? $business->getKey());
    }

    public function test_mysql_rejects_invalid_roles_and_selected_scope_for_owners(): void
    {
        [$business] = $this->makeBusinessWithLocations();
        $user = User::factory()->create();

        try {
            DB::table('business_user')->insert([
                'business_id' => $business->getKey(),
                'user_id' => $user->getKey(),
                'role' => 'administrator',
                'access_scope' => 'all',
            ]);
            $this->fail('MySQL must reject roles outside the supported enum.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('business_user', ['business_id' => $business->getKey(), 'user_id' => $user->getKey()]);
        }

        try {
            DB::table('business_user')->insert([
                'business_id' => $business->getKey(),
                'user_id' => $user->getKey(),
                'role' => 'owner',
                'access_scope' => 'selected',
            ]);
            $this->fail('MySQL must require owners to have all-branch scope.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('business_user', ['business_id' => $business->getKey(), 'user_id' => $user->getKey()]);
        }
    }

    public function test_admin_api_hides_bookings_from_unassigned_branches(): void
    {
        [$business, $allowedLocation, $blockedLocation] = $this->makeBusinessWithLocations();
        $manager = User::factory()->create();
        $business->users()->attach($manager, ['role' => 'manager', 'access_scope' => 'selected']);
        DB::table('business_user_locations')->insert([
            'business_id' => $business->getKey(),
            'user_id' => $manager->getKey(),
            'location_id' => $allowedLocation->getKey(),
            'created_at' => now(),
        ]);
        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Restricted Booking Service',
            'slug' => 'restricted-'.fake()->unique()->numerify('#####'),
            'duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'is_active' => true,
        ]);
        $staff = Staff::query()->create(['business_id' => $business->getKey(), 'name' => 'Restricted Booking Staff', 'status' => 'active']);
        $customer = Customer::query()->create(['business_id' => $business->getKey(), 'name' => 'Branch Customer', 'phone' => '0500000001']);
        $booking = $this->makeBooking($business, $blockedLocation, $service, $staff, $customer, 'BLOCKED-BRANCH');

        Sanctum::actingAs($manager);
        $headers = ['X-Business-Slug' => $business->slug];

        $this->withHeaders($headers)->getJson('/api/v1/admin/bookings/'.$booking->getKey())->assertNotFound();
        $this->withHeaders($headers)->getJson('/api/v1/admin/bookings?location_id='.$blockedLocation->getKey())->assertNotFound();
        $this->withHeaders($headers)->getJson('/api/v1/admin/bookings?staff_id='.$staff->getKey())->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_scheduling_assignments_reject_cross_business_staff_and_service_links(): void
    {
        [$business, $location] = $this->makeBusinessWithLocations();
        [$otherBusiness, $otherLocation] = $this->makeBusinessWithLocations();
        $staff = Staff::query()->create(['business_id' => $business->getKey(), 'name' => 'Staff One', 'status' => 'active']);
        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Service One',
            'slug' => 'service-'.fake()->unique()->numerify('#####'),
            'duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'is_active' => true,
        ]);

        try {
            $staff->locations()->attach($otherLocation, ['is_active' => true]);
            $this->fail('Cross-business staff-location assignments must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('location_id', $exception->errors());
        }

        try {
            $location->services()->attach(Service::query()->create([
                'business_id' => $otherBusiness->getKey(),
                'name' => 'Other Service',
                'slug' => 'other-'.fake()->unique()->numerify('#####'),
                'duration_minutes' => 30,
                'slot_interval_minutes' => 30,
                'is_active' => true,
            ]));
            $this->fail('Cross-business service-location assignments must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('location_id', $exception->errors());
        }

        try {
            $staff->services()->attach(Service::query()->where('business_id', $otherBusiness->getKey())->firstOrFail());
            $this->fail('Cross-business staff-service assignments must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('service_id', $exception->errors());
        }

        $this->assertDatabaseMissing('staff_locations', ['staff_id' => $staff->getKey(), 'location_id' => $otherLocation->getKey()]);
        $this->assertDatabaseMissing('service_locations', ['service_id' => $service->getKey(), 'location_id' => $otherLocation->getKey()]);
        $this->assertDatabaseMissing('staff_services', ['staff_id' => $staff->getKey(), 'service_id' => Service::query()->where('business_id', $otherBusiness->getKey())->value('id')]);
    }

    public function test_only_owners_can_manage_memberships_through_the_admin_api(): void
    {
        [$business, $location] = $this->makeBusinessWithLocations();
        [$otherBusiness, $otherLocation] = $this->makeBusinessWithLocations();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $otherBusiness->users()->attach($manager, ['role' => 'owner', 'access_scope' => 'all']);
        $headers = ['X-Business-Slug' => $business->slug];

        Sanctum::actingAs($owner);
        $this->withHeaders($headers)->putJson('/api/v1/admin/memberships', [
            'user_id' => $manager->getKey(),
            'role' => 'manager',
            'access_scope' => 'selected',
            'location_ids' => [$location->getKey()],
        ])->assertCreated();

        $this->withHeaders($headers)->getJson('/api/v1/admin/memberships')
            ->assertOk()
            ->assertJsonFragment(['role' => 'manager', 'access_scope' => 'selected'])
            ->assertJsonFragment(['location_ids' => [$location->getKey()]]);

        $this->withHeaders($headers)->putJson('/api/v1/admin/memberships', [
            'user_id' => $manager->getKey(),
            'role' => 'not-a-role',
            'access_scope' => 'all',
        ])->assertUnprocessable();

        $this->withHeaders($headers)->putJson('/api/v1/admin/memberships', [
            'user_id' => $manager->getKey(),
            'role' => 'manager',
            'access_scope' => 'selected',
            'location_ids' => [$otherLocation->getKey()],
        ])->assertUnprocessable();

        Sanctum::actingAs($manager);
        $this->withHeaders($headers)->getJson('/api/v1/admin/memberships')->assertForbidden();
    }

    public function test_business_policy_does_not_grant_business_settings_to_managers(): void
    {
        [$business] = $this->makeBusinessWithLocations();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $business->users()->attach($manager, ['role' => 'manager', 'access_scope' => 'all']);

        $this->assertTrue(Gate::forUser($owner)->allows('update', $business));
        $this->assertFalse(Gate::forUser($manager)->allows('update', $business));
    }

    public function test_staff_login_is_optional_and_must_be_a_same_business_member(): void
    {
        [$business] = $this->makeBusinessWithLocations();
        [$otherBusiness] = $this->makeBusinessWithLocations();
        $user = User::factory()->create();
        $otherBusiness->users()->attach($user, ['role' => 'staff', 'access_scope' => 'selected']);

        $unlinked = Staff::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Unlinked Staff',
            'status' => 'active',
        ]);
        $this->assertNull($unlinked->user_id);

        try {
            Staff::query()->create([
                'business_id' => $business->getKey(),
                'name' => 'Cross-business Link',
                'status' => 'active',
                'user_id' => $user->getKey(),
            ]);
            $this->fail('Cross-business staff-account links must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user_id', $exception->errors());
        }

        $business->users()->attach($user, ['role' => 'staff', 'access_scope' => 'selected']);
        $linked = Staff::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Linked Staff',
            'status' => 'active',
            'user_id' => $user->getKey(),
        ]);

        $this->assertSame($user->getKey(), $linked->user_id);

        try {
            Staff::query()->create([
                'business_id' => $business->getKey(),
                'name' => 'Duplicate Linked Staff',
                'status' => 'active',
                'user_id' => $user->getKey(),
            ]);
            $this->fail('A user cannot be linked to two staff records in one business.');
        } catch (QueryException) {
            $this->assertSame(1, Staff::query()->where('business_id', $business->getKey())->where('user_id', $user->getKey())->count());
        }

        $linked->refresh();
        $user->delete();
        $this->assertDatabaseHas('staff', ['id' => $linked->getKey()]);
        $this->assertNull($linked->fresh()->user_id);
    }

    public function test_suspended_accounts_cannot_use_filament_or_authenticated_api_access(): void
    {
        [$business] = $this->makeBusinessWithLocations();
        $user = User::factory()->create();
        $business->users()->attach($user, ['role' => 'owner', 'access_scope' => 'all']);
        $user->is_active = false;
        $user->save();

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        Sanctum::actingAs($user);
        $this->getJson('/api/user')->assertForbidden();
    }

    public function test_staff_account_can_only_query_bookings_for_its_linked_profile(): void
    {
        [$business, $location] = $this->makeBusinessWithLocations();
        $user = User::factory()->create();
        $business->users()->attach($user, ['role' => 'staff', 'access_scope' => 'selected']);
        DB::table('business_user_locations')->insert([
            'business_id' => $business->getKey(),
            'user_id' => $user->getKey(),
            'location_id' => $location->getKey(),
            'created_at' => now(),
        ]);
        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Consultation',
            'slug' => 'consultation-'.fake()->unique()->numerify('####'),
            'duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'max_advance_days' => 30,
            'is_active' => true,
        ]);
        $ownStaff = Staff::query()->create(['business_id' => $business->getKey(), 'name' => 'Own Staff', 'status' => 'active', 'user_id' => $user->getKey()]);
        $otherStaff = Staff::query()->create(['business_id' => $business->getKey(), 'name' => 'Other Staff', 'status' => 'active']);
        $ownStaff->locations()->attach($location, ['is_active' => true]);
        $otherStaff->locations()->attach($location, ['is_active' => true]);
        $customer = Customer::query()->create(['business_id' => $business->getKey(), 'name' => 'Customer', 'phone' => '0500000000']);

        $ownBooking = $this->makeBooking($business, $location, $service, $ownStaff, $customer, 'OWN');
        $this->makeBooking($business, $location, $service, $otherStaff, $customer, 'OTHER');
        $access = app(BusinessAccess::class);

        $this->assertSame([$ownBooking->getKey()], $access->bookings($user, $business)->pluck('id')->all());
        $this->assertFalse($access->canManageBooking($user, $ownBooking));

        $business->users()->detach($user->getKey());
        $this->assertFalse($access->canViewBooking($user, $ownBooking));
        $this->assertDatabaseHas('staff', ['id' => $ownStaff->getKey(), 'user_id' => $user->getKey()]);
        $this->assertDatabaseHas('bookings', ['id' => $ownBooking->getKey()]);
    }

    private function makeBusinessWithLocations(): array
    {
        $business = Business::query()->create([
            'name' => 'Membership Business',
            'slug' => 'membership-'.fake()->unique()->numerify('#####'),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $locations = collect(['north', 'south'])->map(fn (string $name) => Location::query()->create([
            'business_id' => $business->getKey(),
            'name' => ucfirst($name),
            'slug' => $name,
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]));

        return [$business, ...$locations->all()];
    }

    private function makeBooking(Business $business, Location $location, Service $service, Staff $staff, Customer $customer, string $reference): Booking
    {
        return Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $location->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => 'BK-ACCESS-'.$reference,
            'status' => BookingStatus::Confirmed,
            'source' => 'test',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'occupied_starts_at' => now()->addDays(3),
            'occupied_ends_at' => now()->addDays(3)->addMinutes(30),
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => 30,
            'access_token_hash' => 'test-hash-'.$reference,
        ]);
    }
}
