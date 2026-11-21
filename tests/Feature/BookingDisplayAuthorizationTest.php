<?php

namespace Tests\Feature;

use App\Domain\Authorization\BookingDisplayMembershipContext;
use App\Domain\Authorization\BusinessMembershipRequestContext;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingDisplayAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_booking_rows_and_actions_match_across_context_modes(
        BusinessRole $role,
        BranchAccessScope $scope,
        bool $linked,
        array $visibleBookings,
        bool $canManage,
    ): void {
        $user = User::factory()->create();
        [$business, $bookings] = $this->bookingFixture('primary', $user, $role, $scope, $linked);
        $this->actingAs($user, 'web');

        foreach ([false, true] as $confirmedOnly) {
            $visible = $visibleBookings;
            if ($confirmedOnly) {
                $visible = array_values(array_filter(
                    $visible,
                    fn (string $name): bool => $bookings[$name]->status === BookingStatus::Confirmed,
                ));
            }

            $expected = [];
            foreach ($visible as $name) {
                $booking = $bookings[$name];
                $expected[$booking->getKey()] = $canManage ? match ($booking->status) {
                    BookingStatus::Held => ['cancel', 'confirm', 'reschedule'],
                    BookingStatus::Confirmed => ['cancel', 'check_in', 'no_show', 'reschedule'],
                    BookingStatus::CheckedIn => ['complete', 'no_show'],
                    default => [],
                } : [];
            }
            ksort($expected);

            foreach (['off', 'legacy', 'unified'] as $mode) {
                $this->setBookingContextMode($mode);
                $page = $this->get($this->pageUrl($business, $confirmedOnly))->assertOk();
                $this->assertSame($expected, $this->bookingActions($page->getContent()));
                $this->assertSame($canManage, str_contains(
                    $this->decodeHtml($page->getContent()),
                    "wire:click=\"mountTableAction('create_booking')\"",
                ));
            }
        }
    }

    public static function roles(): array
    {
        $north = ['held', 'confirmed', 'checked-in', 'cancelled', 'another-staff'];

        return [
            'owner' => [BusinessRole::Owner, BranchAccessScope::All, false, [...$north, 'south'], true],
            'manager all' => [BusinessRole::Manager, BranchAccessScope::All, false, [...$north, 'south'], true],
            'manager selected' => [BusinessRole::Manager, BranchAccessScope::Selected, false, $north, true],
            'receptionist selected' => [BusinessRole::Receptionist, BranchAccessScope::Selected, false, $north, true],
            'staff selected and linked' => [BusinessRole::Staff, BranchAccessScope::Selected, true, ['held', 'confirmed', 'checked-in', 'cancelled'], false],
            'staff all without link' => [BusinessRole::Staff, BranchAccessScope::All, false, [], false],
        ];
    }

    public static function contextModes(): array
    {
        return [
            'no context' => ['off'],
            'legacy context' => ['legacy'],
            'unified context' => ['unified'],
        ];
    }

    public function test_unified_booking_context_is_independent_and_takes_precedence_over_legacy(): void
    {
        $user = User::factory()->create();
        [$business] = $this->bookingFixture('unified-context', $user, BusinessRole::Manager, BranchAccessScope::Selected);
        $this->actingAs($user, 'web');
        config([
            'booking.authorization_display_context' => true,
            'booking.unified_authorization_display_context' => true,
            'booking.resource_authorization_display_context.enabled' => false,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->get($this->pageUrl($business))->assertOk();
            $membershipQueries = count(array_filter(
                DB::getQueryLog(),
                static fn (array $query): bool => preg_match('/\bfrom\s+\x60?business_user\x60?(?=\s|$)/i', $query['query']) === 1,
            ));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(1, $membershipQueries);
        $this->assertTrue(BusinessMembershipRequestContext::isActiveFor($user, $business));
        $this->assertNull(BookingDisplayMembershipContext::membership($user, $business));
    }

    private function setBookingContextMode(string $mode): void
    {
        config([
            'booking.authorization_display_context' => $mode === 'legacy',
            'booking.unified_authorization_display_context' => $mode === 'unified',
        ]);
    }

    #[DataProvider('contextModes')]
    public function test_switching_tenants_and_users_does_not_reuse_display_authorization(string $mode): void
    {
        $this->setBookingContextMode($mode);
        $user = User::factory()->create();
        [$first, $firstBookings] = $this->bookingFixture('first', $user, BusinessRole::Owner, BranchAccessScope::All);
        [$second, $secondBookings] = $this->bookingFixture('second', $user, BusinessRole::Staff, BranchAccessScope::Selected, linked: true);
        $otherUser = User::factory()->create();
        $this->actingAs($user, 'web');

        $firstPage = $this->bookingActions($this->get($this->pageUrl($first, confirmedOnly: true))->assertOk()->getContent());
        $this->assertSame(['cancel', 'check_in', 'no_show', 'reschedule'], $firstPage[$firstBookings['confirmed']->getKey()]);
        $this->assertSame(
            [$secondBookings['confirmed']->getKey() => []],
            $this->bookingActions($this->get($this->pageUrl($second, confirmedOnly: true))->assertOk()->getContent()),
        );
        $this->assertSame($firstPage, $this->bookingActions(
            $this->get($this->pageUrl($first, confirmedOnly: true))->assertOk()->getContent(),
        ));

        $this->actingAs($otherUser, 'web');
        $this->get($this->pageUrl($first))->assertForbidden();

        app(ManageBusinessMemberships::class)->save(
            $first,
            $otherUser,
            BusinessRole::Receptionist,
            BranchAccessScope::Selected,
            [$firstBookings['south']->location_id],
        );
        $this->assertSame(
            [$firstBookings['south']->getKey() => ['cancel', 'check_in', 'no_show', 'reschedule']],
            $this->bookingActions($this->get($this->pageUrl($first))->assertOk()->getContent()),
        );
        $this->get($this->pageUrl($second))->assertNotFound();
    }

    #[DataProvider('contextModes')]
    public function test_livewire_rejects_a_mounted_cancel_after_role_change(string $mode): void
    {
        $this->setBookingContextMode($mode);
        Queue::fake();
        $user = User::factory()->create();
        [$business, $bookings] = $this->bookingFixture('role-change', $user, BusinessRole::Manager, BranchAccessScope::Selected, linked: true);
        $booking = $bookings['confirmed'];
        $this->actingAs($user, 'web');
        $snapshot = $this->pageSnapshot($this->get($this->pageUrl($business))->assertOk()->getContent());
        $mounted = $this->mountCancel($snapshot, $booking);

        app(ManageBusinessMemberships::class)->save(
            $business,
            $user,
            BusinessRole::Staff,
            BranchAccessScope::Selected,
            [$booking->location_id],
        );

        $this->livewireCall($mounted, 'callMountedTableAction')->assertOk();
        $this->assertBookingWasNotChanged($booking);
        $this->assertSame([$booking->getKey() => []], $this->bookingActions(
            $this->get($this->pageUrl($business, confirmedOnly: true))->assertOk()->getContent(),
        ));

        app(ManageBusinessMemberships::class)->save(
            $business,
            $user,
            BusinessRole::Manager,
            BranchAccessScope::Selected,
            [$booking->location_id],
        );
        $snapshot = $this->pageSnapshot($this->get($this->pageUrl($business))->assertOk()->getContent());
        $this->livewireCall($this->mountCancel($snapshot, $booking), 'callMountedTableAction')->assertOk();
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertDatabaseHas('booking_status_histories', ['booking_id' => $booking->getKey(), 'to_status' => 'cancelled']);
    }

    #[DataProvider('contextModes')]
    public function test_livewire_rechecks_revoked_tenant_membership_before_submitting(string $mode): void
    {
        $this->setBookingContextMode($mode);
        Queue::fake();
        $user = User::factory()->create();
        [$first, $bookings] = $this->bookingFixture('revoked', $user, BusinessRole::Manager, BranchAccessScope::All);
        [$second] = $this->bookingFixture('kept', $user, BusinessRole::Owner, BranchAccessScope::All);
        $booking = $bookings['confirmed'];
        $this->actingAs($user, 'web');
        $snapshot = $this->pageSnapshot($this->get($this->pageUrl($first))->assertOk()->getContent());
        $mounted = $this->mountCancel($snapshot, $booking);

        app(ManageBusinessMemberships::class)->remove($first, $user);

        $this->livewireCall($mounted, 'callMountedTableAction')->assertNotFound();
        $this->get($this->pageUrl($first))->assertNotFound();
        $this->get($this->pageUrl($second))->assertOk();
        $this->assertBookingWasNotChanged($booking);
    }

    #[DataProvider('contextModes')]
    public function test_livewire_rechecks_selected_locations_after_permissions_change(string $mode): void
    {
        $this->setBookingContextMode($mode);
        Queue::fake();
        $user = User::factory()->create();
        [$business, $bookings] = $this->bookingFixture('location-permission-change', $user, BusinessRole::Receptionist, BranchAccessScope::Selected);
        $northBooking = $bookings['confirmed'];
        $southBooking = $bookings['south'];
        $this->actingAs($user, 'web');
        $snapshot = $this->pageSnapshot($this->get($this->pageUrl($business))->assertOk()->getContent());
        $mounted = $this->mountCancel($snapshot, $northBooking);

        app(ManageBusinessMemberships::class)->save(
            $business,
            $user,
            BusinessRole::Receptionist,
            BranchAccessScope::Selected,
            [$southBooking->location_id],
        );

        $this->livewireCall($mounted, 'callMountedTableAction')->assertOk();
        $this->assertBookingWasNotChanged($northBooking);
        $this->assertSame(
            [$southBooking->getKey() => ['cancel', 'check_in', 'no_show', 'reschedule']],
            $this->bookingActions($this->get($this->pageUrl($business, confirmedOnly: true))->assertOk()->getContent()),
        );
    }

    #[DataProvider('contextModes')]
    public function test_livewire_cannot_mount_an_action_for_an_out_of_scope_booking(string $mode): void
    {
        $this->setBookingContextMode($mode);
        Queue::fake();
        $user = User::factory()->create();
        [$business, $bookings] = $this->bookingFixture('branch', $user, BusinessRole::Receptionist, BranchAccessScope::Selected);
        $this->actingAs($user, 'web');
        $snapshot = $this->pageSnapshot($this->get($this->pageUrl($business))->assertOk()->getContent());
        $booking = $bookings['south'];

        $response = $this->livewireCall($snapshot, 'mountTableAction', ['cancel', $booking->getKey()])->assertOk();
        $next = $response->json('components.0.snapshot');
        $this->assertSame([], json_decode($next, true, flags: JSON_THROW_ON_ERROR)['data']['mountedTableActions'][0]);
        $this->livewireCall($next, 'callMountedTableAction')->assertOk();
        $this->assertBookingWasNotChanged($booking);
    }

    private function assertBookingWasNotChanged(Booking $booking): void
    {
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseMissing('booking_status_histories', ['booking_id' => $booking->getKey()]);
        $this->assertDatabaseMissing('outbox_events', ['aggregate_id' => $booking->getKey()]);
    }

    private function mountCancel(string $snapshot, Booking $booking): string
    {
        $next = $this->livewireCall($snapshot, 'mountTableAction', ['cancel', $booking->getKey()])
            ->assertOk()
            ->json('components.0.snapshot');
        $this->assertSame(['cancel'], json_decode($next, true, flags: JSON_THROW_ON_ERROR)['data']['mountedTableActions'][0]);

        return $next;
    }

    private function livewireCall(string $snapshot, string $method, array $parameters = []): TestResponse
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => $method, 'params' => $parameters]],
        ]]], ['X-Livewire' => 'true']);
    }

    private function pageSnapshot(string $html): string
    {
        preg_match_all('/\bwire:snapshot="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = $this->decodeHtml($encoded);
            $data = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            if (str_ends_with($data['memo']['name'], 'list-bookings')) {
                return $snapshot;
            }
        }

        $this->fail('The bookings page did not render a Livewire snapshot.');
    }

    private function pageUrl(Business $business, bool $confirmedOnly = false): string
    {
        $parameters = ['tenant' => $business->slug];
        if ($confirmedOnly) {
            $parameters['tableFilters'] = ['status' => ['value' => 'confirmed']];
        }

        return route('filament.admin.resources.bookings.index', $parameters);
    }

    private function bookingActions(string $html): array
    {
        $html = $this->decodeHtml($html);
        preg_match_all('/\bwire:key="[^"]*\.table\.records\.([a-z0-9]+)"/i', $html, $keys);
        $actions = array_fill_keys($keys[1], []);
        preg_match_all('/\bwire:click="mountTableAction\(\'([a-z_]+)\',\s*\'([a-z0-9]+)\'\)"/i', $html, $buttons, PREG_SET_ORDER);
        foreach ($buttons as [, $action, $record]) {
            $actions[$record][] = $action;
        }
        foreach ($actions as &$recordActions) {
            $recordActions = array_values(array_unique($recordActions));
            sort($recordActions);
        }
        ksort($actions);

        return $actions;
    }

    private function decodeHtml(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    }

    /** @return array{Business, array<string, Booking>} */
    private function bookingFixture(
        string $slug,
        User $user,
        BusinessRole $role,
        BranchAccessScope $scope,
        bool $linked = false,
    ): array {
        $business = Business::query()->create([
            'name' => 'Display authorization business',
            'slug' => $slug,
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $locations = $business->locations();
        $north = $locations->create(['name' => 'North', 'slug' => 'north', 'timezone' => 'Asia/Riyadh', 'is_active' => true]);
        $south = $locations->create(['name' => 'South', 'slug' => 'south', 'timezone' => 'Asia/Riyadh', 'is_active' => true]);
        app(ManageBusinessMemberships::class)->save($business, $user, $role, $scope, [$north->getKey()]);
        $service = $business->services()->create([
            'name' => 'Consultation',
            'slug' => 'consultation',
            'duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'max_advance_days' => 30,
            'is_active' => true,
        ]);
        $staff = $business->staff()->create(['name' => 'Staff member', 'status' => 'active', 'user_id' => $linked ? $user->getKey() : null]);
        $otherStaff = $business->staff()->create(['name' => 'Second staff member', 'status' => 'active']);
        $customer = $business->customers()->create(['name' => 'Fixture customer', 'phone' => '0500000000']);
        $bookings = [];
        $cases = [
            'held' => [$north, $staff, BookingStatus::Held],
            'confirmed' => [$north, $staff, BookingStatus::Confirmed],
            'checked-in' => [$north, $staff, BookingStatus::CheckedIn],
            'cancelled' => [$north, $staff, BookingStatus::Cancelled],
            'another-staff' => [$north, $otherStaff, BookingStatus::Confirmed],
            'south' => [$south, $staff, BookingStatus::Confirmed],
        ];
        $startsAt = now()->addDays(3)->startOfDay()->addHours(8);
        foreach ($cases as $name => [$location, $assignedStaff, $status]) {
            $bookings[$name] = $this->createBooking($business, $location, $service, $assignedStaff, $customer, $name, $status, $startsAt);
            $startsAt = $startsAt->copy()->addHour();
        }

        return [$business, $bookings];
    }

    private function createBooking(
        Business $business,
        Location $location,
        Service $service,
        Staff $staff,
        Customer $customer,
        string $name,
        BookingStatus $status,
        Carbon $startsAt,
    ): Booking {
        $endsAt = $startsAt->copy()->addMinutes(30);

        return $business->bookings()->create([
            'location_id' => $location->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => $business->slug.'-'.$name,
            'status' => $status,
            'source' => 'test',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'occupied_starts_at' => $startsAt,
            'occupied_ends_at' => $endsAt,
            'hold_expires_at' => $status === BookingStatus::Held ? now()->addMinutes(15) : null,
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => 30,
        ]);
    }
}
