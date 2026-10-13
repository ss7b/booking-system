<?php

namespace Tests\Feature;

use App\Domain\Notifications\CustomerNotificationDelivery;
use App\Domain\Notifications\CustomerNotificationService;
use App\Domain\Notifications\NotificationCenter;
use App\Domain\Notifications\ProcessBookingNotification;
use App\Enums\BookingStatus;
use App\Filament\Pages\CustomerNotificationSettings as CustomerNotificationSettingsPage;
use App\Filament\Pages\NotificationCenter as NotificationCenterPage;
use App\Livewire\Filament\NotificationIndicator;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\CustomerNotificationAttempt;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_customer_event_delivery_is_opt_in_idempotent_and_stays_outside_booking_processing(): void
    {
        Queue::fake();
        [$business, , $booking] = $this->makeBookingFixture();
        $notifications = app(CustomerNotificationService::class);
        $settings = $notifications->settings($business);
        $settingsData = $settings->settings;
        $settingsData['channels']['email']['enabled'] = true;
        $settingsData['channels']['email']['credentials'] = [
            'host' => 'smtp.example.test',
            'port' => 587,
            'from_address' => 'bookings@example.test',
        ];
        $settingsData['rules']['booking.created'] = ['enabled' => true, 'channels' => ['email']];
        $settings->settings = $settingsData;
        $settings->save();

        $event = OutboxEvent::withoutEvents(fn () => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingConfirmed',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey()],
            'occurred_at' => now(),
        ]));

        $first = $notifications->processOutboxEvent('BookingConfirmed', [
            'booking_id' => $booking->getKey(),
            'event_id' => $event->getKey(),
        ]);
        $second = $notifications->processOutboxEvent('BookingConfirmed', [
            'booking_id' => $booking->getKey(),
            'event_id' => $event->getKey(),
        ]);

        $this->assertNotNull($first);
        $this->assertSame($first->getKey(), $second?->getKey());
        $this->assertDatabaseCount('customer_notifications', 1);
        $this->assertSame(['email'], $first->channels);
        $this->assertSame('queued', $first->status);

        $settingsData['rules']['booking.rescheduled'] = ['enabled' => true, 'channels' => ['email']];
        $settingsData['rules']['booking.cancelled'] = ['enabled' => true, 'channels' => ['email']];
        $settings->settings = $settingsData;
        $settings->save();

        $booking->starts_at = $booking->starts_at->addHour();
        $booking->save();
        $rescheduledEvent = OutboxEvent::withoutEvents(fn () => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingRescheduled',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey()],
            'occurred_at' => now()->addSecond(),
        ]));
        $rescheduled = $notifications->processOutboxEvent('BookingRescheduled', [
            'booking_id' => $booking->getKey(),
            'event_id' => $rescheduledEvent->getKey(),
        ]);
        $this->assertSame('booking.rescheduled', $rescheduled?->event_type);

        $booking->status = BookingStatus::Cancelled;
        $booking->save();
        $cancelledEvent = OutboxEvent::withoutEvents(fn () => OutboxEvent::query()->create([
            'business_id' => $business->getKey(),
            'event_type' => 'BookingCancelled',
            'aggregate_type' => 'Booking',
            'aggregate_id' => $booking->getKey(),
            'payload' => ['booking_id' => $booking->getKey()],
            'occurred_at' => now()->addSeconds(2),
        ]));
        $cancelled = $notifications->processOutboxEvent('BookingCancelled', [
            'booking_id' => $booking->getKey(),
            'event_id' => $cancelledEvent->getKey(),
        ]);
        $this->assertSame('booking.cancelled', $cancelled?->event_type);
        $this->assertNull($notifications->processOutboxEvent('BookingRescheduled', [
            'booking_id' => $booking->getKey(),
            'event_id' => $rescheduledEvent->getKey(),
        ]));
        $this->assertDatabaseCount('customer_notifications', 3);
    }

    public function test_customer_notification_falls_back_after_provider_rejection_and_tracks_delivery_states(): void
    {
        Queue::fake();
        [$business, , $booking] = $this->makeBookingFixture();
        $booking->customer->update(['phone' => '+15551234567']);
        $settings = app(CustomerNotificationService::class)->settings($business);
        $config = $settings->settings;
        foreach (['sms', 'whatsapp'] as $channel) {
            $config['channels'][$channel] = [
                'enabled' => true,
                'credentials' => [
                    'account_sid' => 'AC'.str_repeat('a', 32),
                    'auth_token' => 'test-token',
                    'from' => '+15557654321',
                ],
            ];
            $config['templates']['booking.reminder'][$channel]['enabled'] = true;
        }
        $config['templates']['booking.reminder']['whatsapp']['content_sid'] = 'HX'.str_repeat('b', 32);
        $settings->settings = $config;
        $settings->save();

        $notification = CustomerNotification::query()->create([
            'business_id' => $business->getKey(),
            'booking_id' => $booking->getKey(),
            'customer_id' => $booking->customer_id,
            'event_type' => 'booking.reminder',
            'idempotency_key' => hash('sha256', 'delivery-fallback-test'),
            'channels' => ['sms', 'whatsapp'],
            'appointment_starts_at' => $booking->starts_at,
            'appointment_fingerprint' => app(CustomerNotificationService::class)->appointmentFingerprint($booking),
            'status' => 'queued',
        ]);

        Http::fakeSequence()
            ->push(['code' => 21211], 400)
            ->push(['sid' => 'SM'.str_repeat('c', 32)], 201);

        $delivery = app(CustomerNotificationDelivery::class);
        $delivery->deliver((string) $notification->getKey());
        $this->assertSame('queued', $notification->fresh()->status);
        $this->assertSame(1, $notification->fresh()->channel_index);

        $delivery->deliver((string) $notification->getKey());
        $this->assertSame('accepted', $notification->fresh()->status);
        $this->assertDatabaseCount('customer_notification_attempts', 2);
        $this->assertDatabaseHas('customer_notification_attempts', [
            'customer_notification_id' => $notification->getKey(),
            'channel' => 'sms',
            'status' => 'permanent',
        ]);
        $this->assertDatabaseHas('customer_notification_attempts', [
            'customer_notification_id' => $notification->getKey(),
            'channel' => 'whatsapp',
            'status' => 'accepted',
            'provider_message_id' => 'SM'.str_repeat('c', 32),
        ]);
        Http::assertSentCount(2);
    }

    public function test_customer_provider_circuit_opens_and_allows_one_recovery_attempt_after_cooldown(): void
    {
        Queue::fake();
        [$business, , $booking] = $this->makeBookingFixture();
        $booking->customer->update(['phone' => '+15551234567']);
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $settings = app(CustomerNotificationService::class)->settings($business);
        $config = $settings->settings;
        $config['channels']['sms'] = [
            'enabled' => true,
            'credentials' => [
                'account_sid' => 'AC'.str_repeat('a', 32),
                'auth_token' => 'test-token',
                'from' => '+15557654321',
            ],
        ];
        $settings->settings = $config;
        $settings->save();

        $key = 'customer-notifications:provider:'.$business->getKey().':sms';
        Cache::forget($key.':failures');
        Cache::forget($key.':open-until');
        Cache::forget($key.':recovery');
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 30001], 503)]);

        $delivery = app(CustomerNotificationDelivery::class);
        $createNotification = function (string $keySuffix) use ($business, $booking): CustomerNotification {
            return CustomerNotification::query()->create([
                'business_id' => $business->getKey(),
                'booking_id' => $booking->getKey(),
                'customer_id' => $booking->customer_id,
                'event_type' => 'booking.reminder',
                'idempotency_key' => hash('sha256', 'circuit-test:'.$keySuffix),
                'channels' => ['sms'],
                'appointment_starts_at' => $booking->starts_at,
                'appointment_fingerprint' => app(CustomerNotificationService::class)->appointmentFingerprint($booking),
                'status' => 'queued',
            ]);
        };

        foreach (range(1, 5) as $attempt) {
            $notification = $createNotification((string) $attempt);
            $delivery->deliver((string) $notification->getKey());
        }

        $cooldownUntil = Cache::get($key.':open-until');
        $this->assertIsInt($cooldownUntil);
        $this->assertGreaterThan(now()->timestamp, $cooldownUntil);
        Http::assertSentCount(5);

        $this->travel(6)->minutes();
        $recovery = $createNotification('recovery');
        $delivery->deliver((string) $recovery->getKey());
        $this->assertSame('failed', $recovery->fresh()->status);
        $reopenedUntil = Cache::get($key.':open-until');
        $this->assertGreaterThan(now()->timestamp, $reopenedUntil);
        $this->assertGreaterThan($cooldownUntil, $reopenedUntil);

        $blocked = $createNotification('blocked-during-cooldown');
        $delivery->deliver((string) $blocked->getKey());
        Http::assertSentCount(6);
        $this->assertSame('failed', $blocked->fresh()->status);

        Cache::forget($key.':failures');
        Cache::forget($key.':open-until');
        Cache::forget($key.':recovery');
    }

    public function test_customer_notification_settings_are_owner_only_and_render_without_exposing_credentials(): void
    {
        [$business] = $this->makeBookingFixture();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $business->users()->attach($manager, ['role' => 'manager', 'access_scope' => 'all']);

        $this->actingAs($owner, 'web')
            ->get('/admin/'.$business->slug.'/customer-notifications')
            ->assertOk()
            ->assertSee('Provider connections')
            ->assertSee('Email via SMTP')
            ->assertSee('SMS via Twilio')
            ->assertSee('WhatsApp via Twilio')
            ->assertSee('Fallback channel 1')
            ->assertSee('Booking confirmation')
            ->assertSee('Recent customer notifications');

        $this->actingAs($manager, 'web')
            ->get('/admin/'.$business->slug.'/customer-notifications')
            ->assertForbidden();

        $settings = app(CustomerNotificationService::class)->settings($business);
        $config = $settings->settings;
        $config['channels']['sms']['credentials']['auth_token'] = 'never-return-this-token';
        $settings->settings = $config;
        $settings->save();

        $this->assertStringNotContainsString('never-return-this-token', (string) DB::table('business_customer_notification_settings')->where('business_id', $business->getKey())->value('settings'));
        $this->assertSame('never-return-this-token', $settings->fresh()->settings['channels']['sms']['credentials']['auth_token']);
    }

    public function test_customer_notification_settings_save_ordered_channels_and_retain_disabled_template_content(): void
    {
        Queue::fake();
        Http::fake();
        [$business] = $this->makeBookingFixture();
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);

        Livewire::actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($business);

        $component = Livewire::test(CustomerNotificationSettingsPage::class)
            ->set('rules.booking_created.enabled', true)
            ->set('rules.booking_created.channels', ['sms', 'email', ''])
            ->set('templates.booking_created.email.enabled', false)
            ->set('templates.booking_created.email.subject', 'Saved for later')
            ->set('templates.booking_created.email.body', 'Retained content')
            ->call('save')
            ->assertHasNoErrors();

        $stored = app(CustomerNotificationService::class)->settings($business)->settings;
        $this->assertTrue($stored['rules']['booking.created']['enabled']);
        $this->assertSame(['sms', 'email'], $stored['rules']['booking.created']['channels']);
        $this->assertFalse($stored['templates']['booking.created']['email']['enabled']);
        $this->assertSame('Saved for later', $stored['templates']['booking.created']['email']['subject']);
        $this->assertSame('Retained content', $stored['templates']['booking.created']['email']['body']);
        $this->assertStringNotContainsString('rules.booking.created', $component->html());

        $component->set('templates.booking_created.email.body', 'Invalid {customer_secret}')
            ->call('save')
            ->assertHasErrors('templates.booking_created.email.body');

        $component->set('rules.booking_created.channels', ['sms', 'sms', ''])
            ->call('save')
            ->assertHasErrors('rules.booking_created.channels');
        Http::assertNothingSent();
    }

    public function test_customer_notification_history_filters_by_status_and_event(): void
    {
        [$business, , $booking] = $this->makeBookingFixture();
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);

        foreach ([['booking.created', 'failed'], ['booking.rescheduled', 'delivered']] as [$event, $status]) {
            CustomerNotification::query()->create([
                'business_id' => $business->getKey(),
                'booking_id' => $booking->getKey(),
                'customer_id' => $booking->customer_id,
                'event_type' => $event,
                'idempotency_key' => hash('sha256', 'history-filter:'.$event),
                'channels' => ['email'],
                'status' => $status,
            ]);
        }

        Livewire::actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($business);

        $component = Livewire::test(CustomerNotificationSettingsPage::class)
            ->assertSee('Booking confirmation')
            ->assertSee('Booking rescheduled');

        $component->set('historyStatus', 'failed');
        $this->assertSame(1, substr_count($component->html(), '<tr class="align-top">'));

        $component->set('historyStatus', '')->set('historyEvent', 'booking.rescheduled');
        $this->assertSame(1, substr_count($component->html(), '<tr class="align-top">'));
    }

    public function test_owner_can_resend_only_a_failed_notification_in_the_current_business(): void
    {
        Queue::fake();
        [$business, , $booking] = $this->makeBookingFixture();
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        $notification = CustomerNotification::query()->create([
            'business_id' => $business->getKey(),
            'booking_id' => $booking->getKey(),
            'customer_id' => $booking->customer_id,
            'event_type' => 'booking.created',
            'idempotency_key' => hash('sha256', 'owner-resend-test'),
            'channels' => ['email'],
            'status' => 'failed',
            'failed_at' => now(),
            'error_code' => 'channels_exhausted',
        ]);

        Livewire::actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($business);
        Livewire::test(CustomerNotificationSettingsPage::class)
            ->call('resend', (string) $notification->getKey());

        $this->assertSame('queued', $notification->fresh()->status);
        $this->assertSame($owner->getKey(), $notification->fresh()->resent_by);
        $this->assertNotNull($notification->fresh()->resent_at);
    }

    public function test_signed_twilio_callback_updates_delivery_state_and_rejects_bad_signatures(): void
    {
        Queue::fake();
        [$business, , $booking] = $this->makeBookingFixture();
        $settings = app(CustomerNotificationService::class)->settings($business);
        $config = $settings->settings;
        $config['channels']['sms']['credentials'] = ['auth_token' => 'callback-secret'];
        $settings->settings = $config;
        $settings->save();
        $notification = CustomerNotification::query()->create([
            'business_id' => $business->getKey(),
            'booking_id' => $booking->getKey(),
            'customer_id' => $booking->customer_id,
            'event_type' => 'booking.created',
            'idempotency_key' => hash('sha256', 'twilio-callback-test'),
            'channels' => ['sms'],
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        $attempt = CustomerNotificationAttempt::query()->create([
            'customer_notification_id' => $notification->getKey(),
            'channel' => 'sms',
            'attempt_number' => 1,
            'status' => 'accepted',
            'provider_message_id' => 'SM'.str_repeat('d', 32),
            'created_at' => now(),
        ]);

        $path = '/api/v1/customer-notification-attempts/'.$attempt->getKey().'/twilio-status';
        $payload = ['MessageSid' => 'SM'.str_repeat('d', 32), 'MessageStatus' => 'delivered'];
        ksort($payload);
        $signatureInput = rtrim((string) config('app.url'), '/').$path;
        foreach ($payload as $key => $value) {
            $signatureInput .= $key.$value;
        }
        $signature = base64_encode(hash_hmac('sha1', $signatureInput, 'callback-secret', true));

        $this->post($path, $payload, ['X-Twilio-Signature' => $signature])->assertNoContent();
        $this->assertSame('delivered', $notification->fresh()->status);
        $this->assertSame('delivered', $attempt->fresh()->status);

        $this->post($path, $payload, ['X-Twilio-Signature' => 'invalid'])->assertForbidden();

        $pending = CustomerNotification::query()->create([
            'business_id' => $business->getKey(),
            'booking_id' => $booking->getKey(),
            'customer_id' => $booking->customer_id,
            'event_type' => 'booking.created',
            'idempotency_key' => hash('sha256', 'twilio-undelivered-callback-test'),
            'channels' => ['sms', 'whatsapp'],
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
        $pendingAttempt = CustomerNotificationAttempt::query()->create([
            'customer_notification_id' => $pending->getKey(),
            'channel' => 'sms',
            'attempt_number' => 1,
            'status' => 'accepted',
            'provider_message_id' => 'SM'.str_repeat('e', 32),
            'created_at' => now(),
        ]);
        $pendingPath = '/api/v1/customer-notification-attempts/'.$pendingAttempt->getKey().'/twilio-status';
        $failedPayload = ['MessageSid' => 'SM'.str_repeat('e', 32), 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003'];
        ksort($failedPayload);
        $signatureInput = rtrim((string) config('app.url'), '/').$pendingPath;
        foreach ($failedPayload as $key => $value) {
            $signatureInput .= $key.$value;
        }
        $failedSignature = base64_encode(hash_hmac('sha1', $signatureInput, 'callback-secret', true));

        $this->post($pendingPath, $failedPayload, ['X-Twilio-Signature' => $failedSignature])->assertNoContent();
        $this->assertSame('queued', $pending->fresh()->status);
        $this->assertSame(1, $pending->fresh()->channel_index);
    }

    public function test_customer_reminders_are_idempotent_and_old_or_missed_appointment_times_are_suppressed(): void
    {
        Queue::fake();
        $now = now()->startOfSecond();
        $this->travelTo($now);
        [$business, , $booking] = $this->makeBookingFixture($now->copy()->addMinutes(60));
        $settings = app(CustomerNotificationService::class)->settings($business);
        $config = $settings->settings;
        $config['channels']['email']['enabled'] = true;
        $config['channels']['email']['credentials'] = [
            'host' => 'smtp.example.test',
            'port' => 587,
            'from_address' => 'bookings@example.test',
        ];
        $config['rules']['booking.reminder'] = ['enabled' => true, 'channels' => ['email'], 'lead_minutes' => 60];
        $settings->settings = $config;
        $settings->save();

        $notifications = app(CustomerNotificationService::class);
        $this->assertSame(1, $notifications->dispatchDueReminders());
        $this->assertSame(1, $notifications->dispatchDueReminders());
        $this->assertDatabaseCount('customer_notifications', 1);

        $originalFingerprint = $notifications->appointmentFingerprint($booking);
        $replacementStaff = Staff::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Replacement Staff',
            'status' => 'active',
        ]);
        $booking->staff_id = $replacementStaff->getKey();
        $booking->save();
        $this->assertSame(1, $notifications->dispatchDueReminders());
        $this->assertDatabaseCount('customer_notifications', 2);

        $reminder = CustomerNotification::query()->where('appointment_fingerprint', $originalFingerprint)->firstOrFail();
        $booking->starts_at = $now->copy()->addMinutes(90);
        $booking->save();
        app(CustomerNotificationDelivery::class)->deliver((string) $reminder->getKey());
        $this->assertSame('superseded', $reminder->fresh()->status);

        $missedBooking = $booking->replicate();
        $missedBooking->reference = 'BK-MISSED-'.fake()->unique()->numerify('#####');
        $missedBooking->starts_at = $now->copy()->addMinutes(30);
        $missedBooking->ends_at = $missedBooking->starts_at->copy()->addMinutes(30);
        $missedBooking->occupied_starts_at = $missedBooking->starts_at;
        $missedBooking->occupied_ends_at = $missedBooking->ends_at;
        $missedBooking->save();

        $notifications->dispatchDueReminders();
        $this->assertDatabaseHas('customer_notifications', [
            'booking_id' => $missedBooking->getKey(),
            'status' => 'skipped',
            'error_code' => 'reminder_time_passed',
        ]);
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
