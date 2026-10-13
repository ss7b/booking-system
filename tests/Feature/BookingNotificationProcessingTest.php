<?php

namespace Tests\Feature;

use App\Domain\Notifications\NotificationCenter;
use App\Domain\Notifications\ProcessBookingNotification;
use App\Enums\BookingStatus;
use App\Filament\Pages\NotificationCenter as NotificationCenterPage;
use App\Livewire\Filament\NotificationIndicator;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class BookingNotificationProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_notification_is_unique_and_excludes_unauthorized_branch_members(): void
    {
        $business = Business::query()->create([
            'name' => 'Notification Business',
            'slug' => 'notification-'.fake()->unique()->numerify('#####'),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $allowed = Location::query()->create(['business_id' => $business->getKey(), 'name' => 'Allowed', 'slug' => 'allowed']);
        $restricted = Location::query()->create(['business_id' => $business->getKey(), 'name' => 'Restricted', 'slug' => 'restricted']);
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
        $booking = Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $restricted->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => 'BK-NOTIFICATION-1',
            'status' => BookingStatus::Confirmed,
            'source' => 'test',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addMinutes(30),
            'occupied_starts_at' => now()->addDays(3),
            'occupied_ends_at' => now()->addDays(3)->addMinutes(30),
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => 30,
        ]);

        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $business->users()->attach($manager, ['role' => 'manager', 'access_scope' => 'selected']);
        DB::table('business_user_locations')->insert([
            'business_id' => $business->getKey(),
            'user_id' => $manager->getKey(),
            'location_id' => $allowed->getKey(),
            'created_at' => now(),
        ]);

        $processor = app(ProcessBookingNotification::class);
        $processor->handle('BookingConfirmed', ['booking_id' => $booking->getKey(), 'event_id' => 'event-one']);
        $processor->handle('BookingConfirmed', ['booking_id' => $booking->getKey(), 'event_id' => 'event-one']);

        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('user_notifications', [
            'business_id' => $business->getKey(),
            'user_id' => $owner->getKey(),
            'booking_id' => $booking->getKey(),
            'notification_type' => 'booking.created',
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $manager->getKey()]);

        $processor->handle('BookingConfirmed', [
            'booking_id' => $booking->getKey(),
            'event_id' => 'event-two',
            'from_status' => 'held',
            'to_status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->getKey(),
            'booking_id' => $booking->getKey(),
            'notification_type' => 'booking.confirmed',
        ]);
        $this->assertDatabaseCount('user_notifications', 2);
    }

    public function test_global_notification_indicator_is_rendered_on_business_management_pages(): void
    {
        [$business, , $booking] = $this->makeBookingFixture();
        $booking->service->locations()->attach($booking->location_id);
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        app(ProcessBookingNotification::class)->handle('BookingConfirmed', [
            'booking_id' => $booking->getKey(),
            'event_id' => 'global-indicator-page-test',
        ]);

        $this->actingAs($owner, 'web');

        foreach (['bookings', 'services', 'customers', 'notifications'] as $page) {
            $response = $this->get('/admin/'.$business->slug.'/'.$page);

            $this->assertSame(200, $response->getStatusCode(), "The {$page} page should be accessible to the business owner.");
            $response
                ->assertSee('wire:poll.5s.visible="poll"', false)
                ->assertSee('Notifications, 1 unread', false);
        }
    }

    public function test_indicator_polls_new_notifications_once_and_updates_after_reading(): void
    {
        [$business, , $booking] = $this->makeBookingFixture();
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        app(ProcessBookingNotification::class)->handle('BookingConfirmed', [
            'booking_id' => $booking->getKey(),
            'event_id' => 'indicator-initial-notification',
        ]);

        Livewire::actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($business);

        $indicator = Livewire::test(NotificationIndicator::class)
            ->assertSet('unreadCount', 1)
            ->call('poll')
            ->assertSet('unreadCount', 1);
        $this->assertCount(0, session('filament.notifications', []));

        app(ProcessBookingNotification::class)->handle('BookingRescheduled', [
            'booking_id' => $booking->getKey(),
            'event_id' => 'indicator-new-notification',
        ]);

        $indicator->call('poll')->assertSet('unreadCount', 2);
        $this->assertCount(1, session('filament.notifications', []));

        $indicator->call('poll')->assertSet('unreadCount', 2);
        $this->assertCount(1, session('filament.notifications', []));

        $notificationId = DB::table('user_notifications')
            ->where('business_id', $business->getKey())
            ->where('user_id', $owner->getKey())
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->value('id');
        app(NotificationCenter::class)->markRead($owner, $business, $notificationId);

        $indicator->call('poll')->assertSet('unreadCount', 1);
        $this->assertCount(1, session('filament.notifications', []));
    }

    public function test_indicator_count_is_scoped_to_current_user_and_business(): void
    {
        [$business, , $booking] = $this->makeBookingFixture();
        $otherBusiness = Business::query()->create([
            'name' => 'Other Indicator Business',
            'slug' => 'other-indicator-'.fake()->unique()->numerify('#####'),
            'timezone' => 'UTC',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $business->users()->attach($otherUser, ['role' => 'manager', 'access_scope' => 'selected']);
        $otherBusiness->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        app(ProcessBookingNotification::class)->handle('BookingConfirmed', [
            'booking_id' => $booking->getKey(),
            'event_id' => 'indicator-scope-test',
        ]);

        Livewire::actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($business);
        Livewire::test(NotificationIndicator::class)->assertSet('unreadCount', 1);

        Livewire::actingAs($otherUser, 'web');
        Filament::setTenant($business);
        Livewire::test(NotificationIndicator::class)->assertSet('unreadCount', 0);

        $this->actingAs($owner, 'web')
            ->get('/admin/'.$otherBusiness->slug.'/notifications')
            ->assertOk()
            ->assertSee('wire:poll.5s.visible="poll"', false)
            ->assertDontSee('Notifications, 1 unread', false)
            ->assertDontSee($booking->reference);
    }

    public function test_notification_api_rechecks_branch_access_and_marks_only_owned_notifications_read(): void
    {
        [$business, $location, $booking] = $this->makeBookingFixture();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $business->users()->attach($manager, ['role' => 'manager', 'access_scope' => 'selected']);
        DB::table('business_user_locations')->insert([
            'business_id' => $business->getKey(),
            'user_id' => $manager->getKey(),
            'location_id' => $location->getKey(),
            'created_at' => now(),
        ]);
        app(ProcessBookingNotification::class)->handle('BookingConfirmed', ['booking_id' => $booking->getKey(), 'event_id' => 'api-event']);
        $this->assertDatabaseHas('user_notifications', [
            'business_id' => $business->getKey(),
            'user_id' => $manager->getKey(),
            'booking_id' => $booking->getKey(),
        ]);
        DB::table('business_user_locations')
            ->where('business_id', $business->getKey())
            ->where('user_id', $manager->getKey())
            ->where('location_id', $location->getKey())
            ->delete();
        $notificationId = DB::table('user_notifications')->where('user_id', $owner->getKey())->value('id');
        $headers = ['X-Business-Slug' => $business->slug];

        $this->actingAs($owner, 'web')
            ->get('/admin/'.$business->slug.'/notifications')
            ->assertOk()
            ->assertSee($booking->reference)
            ->assertSee('wire:poll.10s="refreshInbox"', false)
            ->assertSee('notification-count-updated', false);

        Sanctum::actingAs($owner);
        $this->withHeaders($headers)->getJson('/api/v1/admin/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.booking.reference', $booking->reference);
        $this->withHeaders($headers)->getJson('/api/v1/admin/notifications/unread-count')->assertJsonPath('count', 1);
        $this->withHeaders($headers)->patchJson('/api/v1/admin/notifications/'.$notificationId.'/read')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/admin/notifications/unread-count')->assertJsonPath('count', 0);

        Sanctum::actingAs($manager);
        $this->withHeaders($headers)->getJson('/api/v1/admin/notifications')->assertJsonPath('meta.total', 0);
        $this->withHeaders($headers)->patchJson('/api/v1/admin/notifications/'.$notificationId.'/read')->assertNotFound();

        $this->actingAs($manager, 'web')
            ->get('/admin/'.$business->slug.'/notifications')
            ->assertOk()
            ->assertDontSee($booking->reference);
    }

    public function test_livewire_poll_refreshes_the_notification_list_with_current_user_scope(): void
    {
        [$business, , $booking] = $this->makeBookingFixture();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $business->users()->attach($manager, ['role' => 'manager', 'access_scope' => 'selected']);

        Livewire::actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($business);

        $component = Livewire::test(NotificationCenterPage::class)->assertDontSee($booking->reference);
        app(ProcessBookingNotification::class)->handle('BookingRescheduled', [
            'booking_id' => $booking->getKey(),
            'event_id' => 'livewire-refresh-test',
        ]);

        $component->call('refreshInbox')
            ->assertSet('unreadCount', 1)
            ->assertDispatched('notification-count-updated')
            ->assertSee('Rescheduled')
            ->assertSee($booking->reference);

        Livewire::actingAs($manager, 'web');
        Livewire::test(NotificationCenterPage::class)->assertDontSee($booking->reference);
    }

    public function test_delayed_reschedule_uses_old_and_new_assignment_context_but_rechecks_current_access(): void
    {
        [$business, $oldLocation, $booking] = $this->makeBookingFixture();
        $oldStaffUser = User::factory()->create();
        $newStaffUser = User::factory()->create();
        $oldBranchManager = User::factory()->create();
        $business->users()->attach($oldStaffUser, ['role' => 'staff', 'access_scope' => 'selected']);
        $business->users()->attach($newStaffUser, ['role' => 'staff', 'access_scope' => 'selected']);
        $business->users()->attach($oldBranchManager, ['role' => 'manager', 'access_scope' => 'selected']);

        $newLocation = Location::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'New Branch',
            'slug' => 'new-branch',
            'is_active' => true,
        ]);
        $oldStaff = Staff::query()->findOrFail($booking->staff_id);
        $oldStaff->user_id = $oldStaffUser->getKey();
        $oldStaff->save();
        $newStaff = Staff::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'New Staff',
            'status' => 'active',
            'user_id' => $newStaffUser->getKey(),
        ]);
        $oldStaff->locations()->attach($oldLocation, ['is_active' => true]);
        $newStaff->locations()->attach($newLocation, ['is_active' => true]);

        foreach ([[$oldStaffUser, $oldLocation], [$oldBranchManager, $oldLocation], [$newStaffUser, $newLocation]] as [$user, $location]) {
            DB::table('business_user_locations')->insert([
                'business_id' => $business->getKey(),
                'user_id' => $user->getKey(),
                'location_id' => $location->getKey(),
                'created_at' => now(),
            ]);
        }

        $payload = [
            'booking_id' => $booking->getKey(),
            'reference' => $booking->reference,
            'old_staff_id' => $oldStaff->getKey(),
            'new_staff_id' => $newStaff->getKey(),
            'old_staff_user_id' => $oldStaffUser->getKey(),
            'new_staff_user_id' => $newStaffUser->getKey(),
            'old_location_id' => $oldLocation->getKey(),
            'new_location_id' => $newLocation->getKey(),
        ];

        $booking->location_id = $newLocation->getKey();
        $booking->staff_id = $newStaff->getKey();
        $booking->save();
        DB::table('business_user_locations')
            ->where('business_id', $business->getKey())
            ->where('user_id', $oldBranchManager->getKey())
            ->where('location_id', $oldLocation->getKey())
            ->delete();

        app(ProcessBookingNotification::class)->handle('BookingRescheduled', $payload + ['event_id' => 'delayed-reschedule']);

        $this->assertDatabaseHas('user_notifications', [
            'business_id' => $business->getKey(),
            'user_id' => $oldStaffUser->getKey(),
            'booking_id' => null,
            'notification_type' => 'booking.rescheduled',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'business_id' => $business->getKey(),
            'user_id' => $newStaffUser->getKey(),
            'booking_id' => $booking->getKey(),
            'notification_type' => 'booking.rescheduled',
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $oldBranchManager->getKey()]);

        $oldStaff->user_id = null;
        $oldStaff->save();
        app(ProcessBookingNotification::class)->handle('BookingRescheduled', $payload + ['event_id' => 'unlinked-reschedule']);

        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $oldStaffUser->getKey())->count());
    }

    public function test_preferences_are_membership_scoped_and_mandatory_rules_cannot_be_muted(): void
    {
        [$business] = $this->makeBookingFixture();
        $otherBusiness = Business::query()->create([
            'name' => 'Other Notification Business',
            'slug' => 'other-notification-'.fake()->unique()->numerify('#####'),
            'timezone' => 'UTC',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $otherBusiness->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $this->withHeaders(['X-Business-Slug' => $business->slug]);
        Sanctum::actingAs($owner);

        $this->putJson('/api/v1/admin/notification-preferences', [
            'preferences' => [['notification_type' => 'booking.created', 'enabled' => false]],
        ])->assertUnprocessable();

        try {
            DB::table('business_notification_settings')->insert([
                'business_id' => $business->getKey(),
                'role' => 'owner',
                'notification_type' => 'booking.cancelled',
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The database must reject disabling a mandatory notification type.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('business_notification_settings', [
                'business_id' => $business->getKey(),
                'role' => 'owner',
                'notification_type' => 'booking.cancelled',
            ]);
        }

        $this->putJson('/api/v1/admin/notification-preferences', [
            'preferences' => [['notification_type' => 'booking.confirmed', 'enabled' => false]],
        ])->assertOk();
        $this->getJson('/api/v1/admin/notification-preferences')
            ->assertJsonFragment(['notification_type' => 'booking.confirmed', 'mandatory' => false, 'enabled' => false]);

        $this->withHeaders(['X-Business-Slug' => $otherBusiness->slug]);
        $this->getJson('/api/v1/admin/notification-preferences')
            ->assertJsonFragment(['notification_type' => 'booking.confirmed', 'mandatory' => false, 'enabled' => true]);

        $this->withHeaders(['X-Business-Slug' => $business->slug]);
        $manager = User::factory()->create();
        $business->users()->attach($manager, ['role' => 'manager', 'access_scope' => 'all']);
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/admin/notification-settings')->assertForbidden();
    }

    public function test_reminders_use_current_booking_time_and_are_idempotent(): void
    {
        [$business, , $booking] = $this->makeBookingFixture(now()->addMinutes(59));
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        DB::table('business_notification_settings')->insert([
            'business_id' => $business->getKey(),
            'role' => 'owner',
            'notification_type' => 'booking.reminder_due',
            'enabled' => true,
            'reminder_intervals' => json_encode([60]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $booking->starts_at = now()->addHours(2);
        $booking->save();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->assertDatabaseCount('user_notifications', 0);

        $booking->starts_at = now()->addMinutes(59);
        $booking->save();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('user_notifications', ['notification_type' => 'booking.reminder_due', 'booking_id' => $booking->getKey()]);

        $booking->status = BookingStatus::Cancelled;
        $booking->save();
        $booking->starts_at = now()->addMinutes(59);
        $booking->save();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->assertDatabaseCount('user_notifications', 1);
    }

    public function test_reminder_intervals_are_limited_to_the_configured_recipient_role(): void
    {
        [$business, , $booking] = $this->makeBookingFixture(now()->addMinutes(90));
        $initialStart = $booking->starts_at->format('U.u');
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $business->users()->attach($manager, ['role' => 'manager', 'access_scope' => 'all']);

        foreach ([[$owner, [60]], [$manager, [120]]] as [$user, $intervals]) {
            DB::table('business_notification_settings')->insert([
                'business_id' => $business->getKey(),
                'role' => $business->users()->whereKey($user->getKey())->firstOrFail()->pivot->role->value,
                'notification_type' => 'booking.reminder_due',
                'enabled' => true,
                'reminder_intervals' => json_encode($intervals),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->assertSame(0, DB::table('user_notifications')->where('user_id', $owner->getKey())->count());
        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $manager->getKey())->count());

        $booking->starts_at = now()->addMinutes(59);
        $booking->save();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $owner->getKey())->count());
        $this->assertSame(2, DB::table('user_notifications')->where('user_id', $manager->getKey())->count());
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->getKey(),
            'booking_id' => $booking->getKey(),
            'idempotency_key' => hash('sha256', 'reminder:'.$booking->getKey().':'.$booking->starts_at->format('U.u').':60:'.$owner->getKey()),
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $manager->getKey(),
            'booking_id' => $booking->getKey(),
            'idempotency_key' => hash('sha256', 'reminder:'.$booking->getKey().':'.$initialStart.':120:'.$manager->getKey()),
        ]);
    }

    public function test_reminder_id_chunking_visits_each_due_booking_once_when_start_order_differs_from_id_order(): void
    {
        [$business, , $firstBooking] = $this->makeBookingFixture(now()->addMinutes(55));
        $bookings = collect([$firstBooking]);

        foreach (range(1, 4) as $index) {
            $booking = $firstBooking->replicate();
            $booking->reference = 'BK-CHUNK-'.fake()->unique()->numerify('#####');
            $booking->save();
            $bookings->push($booking);
        }

        $bookings->sortBy('id')->values()->each(function (Booking $booking, int $index): void {
            $booking->starts_at = now()->addMinutes(55 - ($index * 5));
            $booking->save();
        });

        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        DB::table('business_notification_settings')->insert([
            'business_id' => $business->getKey(),
            'role' => 'owner',
            'notification_type' => 'booking.reminder_due',
            'enabled' => true,
            'reminder_intervals' => json_encode([60]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();

        $this->assertSame(5, DB::table('user_notifications')->where('user_id', $owner->getKey())->count());
        $this->assertSame(5, DB::table('user_notifications')->where('user_id', $owner->getKey())->distinct('booking_id')->count('booking_id'));
    }

    private function makeBookingFixture(?CarbonInterface $startsAt = null): array
    {
        $business = Business::query()->create([
            'name' => 'Notification Fixture',
            'slug' => 'notification-fixture-'.fake()->unique()->numerify('#####'),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Main',
            'slug' => 'main',
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);
        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Consultation',
            'slug' => 'service-'.fake()->unique()->numerify('#####'),
            'duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'is_active' => true,
        ]);
        $staff = Staff::query()->create(['business_id' => $business->getKey(), 'name' => 'Staff', 'status' => 'active']);
        $customer = Customer::query()->create(['business_id' => $business->getKey(), 'name' => 'Customer', 'phone' => '0500000000']);
        $startsAt ??= now()->addDays(3);
        $booking = Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $location->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => 'BK-NOTIFICATION-'.fake()->unique()->numerify('#####'),
            'status' => BookingStatus::Confirmed,
            'source' => 'test',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'occupied_starts_at' => $startsAt,
            'occupied_ends_at' => $startsAt->copy()->addMinutes(30),
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => 30,
        ]);

        return [$business, $location, $booking];
    }
}
