<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Availability\Services\AvailabilityService;
use App\Domain\Booking\DTOs\BookingCreationResult;
use App\Domain\Booking\Services\BookingManagementLink;
use App\Domain\Booking\Services\CreateBooking;
use App\Domain\Booking\Services\UpdateServiceLocationSettings;
use App\Domain\Notifications\ProcessBookingNotification;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\ServiceResource\Pages\ManageServices;
use App\Filament\Widgets\BookingCalendarWidget;
use App\Models\AvailabilityCalendar;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceLocation;
use App\Models\Staff;
use App\Models\User;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class StafflessBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_exposes_location_booking_mode_and_creates_staffless_booking(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 1);
        $fixture['business']->timezone = 'UTC';
        $fixture['business']->metadata = ['booking_management_url' => 'https://clinic.example/manage-booking'];
        $fixture['business']->save();
        $headers = ['X-Business-Slug' => $fixture['business']->slug];

        $this->getJson('/api/v1/services?location_id='.$fixture['location']->getKey(), $headers)
            ->assertOk()
            ->assertJsonPath('data.0.booking_mode', BookingMode::Staffless->value)
            ->assertJsonPath('data.0.requires_staff', false);

        $this->getJson('/api/v1/availability?'.http_build_query([
            'service_id' => $fixture['service']->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'date' => $fixture['date'],
        ]), $headers)
            ->assertOk()
            ->assertJsonPath('data.service.booking_mode', BookingMode::Staffless->value)
            ->assertJsonPath('data.slots.0.staff_id', null);

        $created = $this->postJson('/api/v1/bookings', [
            'service_id' => $fixture['service']->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'start' => $fixture['date'].'T09:00:00+03:00',
            'customer' => ['name' => 'API Customer', 'phone' => '0501000009'],
        ], $headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->assertCreated();
        $this->assertStringEndsWith('+03:00', $created->json('data.starts_at'));
        $expectedUtcStart = (new DateTimeImmutable($fixture['date'].'T09:00:00+03:00'))
            ->setTimezone(new DateTimeZone('UTC'));
        $storedBooking = Booking::query()->where('reference', $created->json('data.reference'))->firstOrFail();
        $this->assertSame($expectedUtcStart->format('U.u'), $storedBooking->starts_at->format('U.u'));

        $this->getJson('/api/v1/bookings/'.$created->json('data.reference'), $headers + [
            'X-Booking-Access-Token' => $created->json('data.access_token'),
        ])->assertOk()->assertJsonPath('data.staff_id', null);

        $managementUrl = app(BookingManagementLink::class)->createUrl($storedBooking);
        $this->assertIsString($managementUrl);
        parse_str((string) parse_url($managementUrl, PHP_URL_FRAGMENT), $fragment);
        $managementHeaders = $headers + ['X-Booking-Management-Token' => $fragment['management_token']];
        $this->getJson('/api/v1/bookings/'.$created->json('data.reference'), $managementHeaders)
            ->assertOk()
            ->assertJsonPath('data.staff_id', null);
        $rescheduled = $this->postJson('/api/v1/bookings/'.$created->json('data.reference').'/reschedule', [
            'start' => $fixture['date'].'T10:00:00+03:00',
        ], $managementHeaders + ['Idempotency-Key' => (string) Str::ulid()])
            ->assertOk()
            ->assertJsonPath('data.staff_id', null);
        $this->assertIsString($rescheduled->json('data.management_url'));

        $this->postJson('/api/v1/bookings', [
            'service_id' => $fixture['service']->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'staff_id' => (string) Str::ulid(),
            'start' => $fixture['date'].'T10:00:00+03:00',
            'customer' => ['name' => 'Invalid API Customer', 'phone' => '0501000010'],
        ], $headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_public_api_keeps_staff_required_contract_for_location_service_assignment(): void
    {
        $fixture = $this->fixture(BookingMode::StaffRequired, 1);
        $staff = Staff::query()->create([
            'business_id' => $fixture['business']->getKey(),
            'name' => 'Required API Staff',
            'status' => 'active',
        ]);
        $staff->services()->sync([$fixture['service']->getKey()]);
        $staff->locations()->sync([$fixture['location']->getKey() => ['is_active' => true]]);
        $headers = ['X-Business-Slug' => $fixture['business']->slug];

        $this->getJson('/api/v1/services?location_id='.$fixture['location']->getKey(), $headers)
            ->assertOk()
            ->assertJsonPath('data.0.booking_mode', BookingMode::StaffRequired->value)
            ->assertJsonPath('data.0.requires_staff', true);

        $this->postJson('/api/v1/bookings', [
            'service_id' => $fixture['service']->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'start' => $fixture['date'].'T09:00:00+03:00',
            'customer' => ['name' => 'Missing Staff API Customer', 'phone' => '0501000020'],
        ], $headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');

        $created = $this->postJson('/api/v1/bookings', [
            'service_id' => $fixture['service']->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'staff_id' => $staff->getKey(),
            'start' => $fixture['date'].'T09:00:00+03:00',
            'customer' => ['name' => 'Valid Staff API Customer', 'phone' => '0501000021'],
        ], $headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->assertCreated();

        $this->getJson('/api/v1/bookings/'.$created->json('data.reference'), $headers + [
            'X-Booking-Access-Token' => $created->json('data.access_token'),
        ])->assertOk()->assertJsonPath('data.staff_id', $staff->getKey());
    }

    public function test_receptionist_can_manage_staffless_bookings_only_at_authorized_locations(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 1);
        $otherLocation = Location::query()->create([
            'business_id' => $fixture['business']->getKey(),
            'calendar_id' => $fixture['location']->calendar_id,
            'name' => 'Other Reception Branch',
            'slug' => 'other-reception-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);
        $otherLocation->services()->attach($fixture['service']->getKey(), [
            'booking_mode' => BookingMode::Staffless->value,
            'capacity' => 1,
        ]);
        $ownBooking = $this->create($fixture, '09:00', '0501000041')->booking;
        $otherBooking = app(CreateBooking::class)->execute(
            $fixture['business'],
            $otherLocation,
            $fixture['service'],
            null,
            $this->start($fixture, '09:00'),
            ['name' => 'Other Branch Customer', 'phone' => '0501000042'],
            null,
            false,
        )->booking;

        $receptionist = User::factory()->create();
        $fixture['business']->users()->attach($receptionist, ['role' => 'receptionist', 'access_scope' => 'selected']);
        DB::table('business_user_locations')->insert([
            'business_id' => $fixture['business']->getKey(),
            'user_id' => $receptionist->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'created_at' => now(),
        ]);

        $this->assertSame(
            [$ownBooking->getKey()],
            app(BusinessAccess::class)->bookings($receptionist, $fixture['business'])->pluck('id')->all(),
        );

        Sanctum::actingAs($receptionist);
        $headers = ['X-Business-Slug' => $fixture['business']->slug];
        $this->withHeaders($headers)->getJson('/api/v1/admin/bookings/'.$ownBooking->getKey())
            ->assertOk()
            ->assertJsonPath('data.staff', null);
        $this->withHeaders($headers)->getJson('/api/v1/admin/bookings/'.$otherBooking->getKey())
            ->assertNotFound();
    }

    public function test_staffless_booking_notifications_resolve_branch_recipients_without_staff(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 1);
        $booking = $this->create($fixture, '09:00', '0501000045')->booking;
        $receptionist = User::factory()->create();
        $staffUser = User::factory()->create();
        $fixture['business']->users()->attach($receptionist, ['role' => 'receptionist', 'access_scope' => 'selected']);
        $fixture['business']->users()->attach($staffUser, ['role' => 'staff', 'access_scope' => 'selected']);
        DB::table('business_user_locations')->insert([
            [
                'business_id' => $fixture['business']->getKey(),
                'user_id' => $receptionist->getKey(),
                'location_id' => $fixture['location']->getKey(),
                'created_at' => now(),
            ],
            [
                'business_id' => $fixture['business']->getKey(),
                'user_id' => $staffUser->getKey(),
                'location_id' => $fixture['location']->getKey(),
                'created_at' => now(),
            ],
        ]);
        $staff = Staff::query()->create([
            'business_id' => $fixture['business']->getKey(),
            'name' => 'Unassigned Staff Recipient',
            'status' => 'active',
            'user_id' => $staffUser->getKey(),
        ]);
        $staff->locations()->sync([$fixture['location']->getKey() => ['is_active' => true]]);
        $fixture['business']->users()->attach(User::factory()->create(), ['role' => 'owner', 'access_scope' => 'all']);

        app(ProcessBookingNotification::class)->handle('BookingConfirmed', [
            'booking_id' => $booking->getKey(),
            'event_id' => 'staffless-notification-test',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'business_id' => $fixture['business']->getKey(),
            'user_id' => $receptionist->getKey(),
            'booking_id' => $booking->getKey(),
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'business_id' => $fixture['business']->getKey(),
            'user_id' => $staffUser->getKey(),
            'booking_id' => $booking->getKey(),
        ]);
    }

    public function test_filament_saves_booking_mode_and_capacity_for_the_selected_location(): void
    {
        $fixture = $this->fixture(BookingMode::StaffRequired, 1);
        $owner = User::factory()->create();
        $fixture['business']->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner, 'web');
        Filament::setTenant($fixture['business']);

        $component = Livewire::test(ManageServices::class)
            ->mountTableAction('edit', $fixture['service']);
        $locationSettings = $component->instance()->mountedTableActionsData[0]['location_settings'];
        $locationKey = array_key_first($locationSettings);

        $component
            ->setTableActionData([
                'location_settings' => [
                    $locationKey => [
                        'location_id' => $fixture['location']->getKey(),
                        'booking_mode' => BookingMode::Staffless->value,
                        'capacity' => 3,
                    ],
                ],
            ])
            ->callMountedTableAction();

        $this->assertDatabaseHas('service_locations', [
            'service_id' => $fixture['service']->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'booking_mode' => BookingMode::Staffless->value,
            'capacity' => 3,
        ]);

        $fixture['service']->locations()->syncWithoutDetaching([$fixture['location']->getKey()]);
        $this->assertDatabaseHas('service_locations', [
            'service_id' => $fixture['service']->getKey(),
            'location_id' => $fixture['location']->getKey(),
            'booking_mode' => BookingMode::Staffless->value,
            'capacity' => 3,
        ]);
    }

    public function test_filament_can_create_and_manage_a_staffless_booking_without_a_staff_assignment(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 1);
        $owner = User::factory()->create();
        $fixture['business']->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner, 'web');
        Filament::setTenant($fixture['business']);

        Livewire::test(ListBookings::class)
            ->mountTableAction('create_booking')
            ->setTableActionData([
                'location_id' => $fixture['location']->getKey(),
                'service_id' => $fixture['service']->getKey(),
                'starts_at' => $fixture['date'].' 09:00:00',
                'customer_name' => 'Filament Staffless Customer',
                'customer_phone' => '0501000061',
            ])
            ->callMountedTableAction();

        $booking = Booking::query()->where('business_id', $fixture['business']->getKey())->firstOrFail();
        $this->assertNull($booking->staff_id);
        $this->assertSame('admin', $booking->source);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        Livewire::test(ListBookings::class)
            ->assertTableColumnStateSet('staff.name', null, $booking);
    }

    public function test_availability_picker_reads_action_form_state_and_waits_for_an_explicit_time_choice(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 1);
        $owner = User::factory()->create();
        $fixture['business']->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner, 'web');
        Filament::setTenant($fixture['business']);

        $component = Livewire::test(ListBookings::class)
            ->mountTableAction('create_booking')
            ->setTableActionData([
                'location_id' => $fixture['location']->getKey(),
                'service_id' => $fixture['service']->getKey(),
                'starts_at' => null,
            ])
            ->assertDontSee('Select a location and service to load the appointment calendar.')
            ->assertSee('Appointment time')
            ->assertSee('Open');

        $component
            ->set(
                'mountedTableActionsData.0.calendar_week_start',
                Carbon::parse($fixture['date'])->startOfWeek(Carbon::SUNDAY)->toDateString(),
            )
            ->set('mountedTableActionsData.0.selected_date', $fixture['date'])
            ->assertSet('mountedTableActionsData.0.starts_at', null)
            ->assertSee('8:00 AM');
    }

    public function test_filament_can_create_a_staff_required_booking_from_the_availability_picker(): void
    {
        $fixture = $this->fixture(BookingMode::StaffRequired, 1);
        $staff = Staff::query()->create([
            'business_id' => $fixture['business']->getKey(),
            'name' => 'Picker Staff',
            'status' => 'active',
        ]);
        $staff->services()->attach($fixture['service']->getKey());
        $staff->locations()->attach($fixture['location']->getKey(), ['is_active' => true]);
        $owner = User::factory()->create();
        $fixture['business']->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner, 'web');
        Filament::setTenant($fixture['business']);

        Livewire::test(ListBookings::class)
            ->mountTableAction('create_booking')
            ->setTableActionData([
                'location_id' => $fixture['location']->getKey(),
                'service_id' => $fixture['service']->getKey(),
                'staff_id' => $staff->getKey(),
                'calendar_week_start' => Carbon::parse($fixture['date'])->startOfWeek(Carbon::SUNDAY)->toDateString(),
                'selected_date' => $fixture['date'],
                'starts_at' => $fixture['date'].' 08:00:00',
                'customer_name' => 'Picker Staff Required Customer',
                'customer_phone' => '0501000091',
            ])
            ->assertDontSee('Select a location and service to load the appointment calendar.')
            ->assertSee('Picker Staff')
            ->assertSee('8:00 AM')
            ->callMountedTableAction();

        $booking = Booking::query()
            ->where('business_id', $fixture['business']->getKey())
            ->where('customer_id', '!=', null)
            ->firstOrFail();

        $this->assertSame($staff->getKey(), $booking->staff_id);
        $this->assertSame('Picker Staff Required Customer', $booking->customer->name);
    }

    public function test_booking_calendar_includes_authorized_staffless_events_without_customer_data(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 1);
        $booking = $this->create($fixture, '09:00', '0501000062')->booking;
        $foreignFixture = $this->fixture(BookingMode::Staffless, 1);
        $this->create($foreignFixture, '09:00', '0501000063');

        $owner = User::factory()->create();
        $fixture['business']->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner, 'web');
        Filament::setTenant($fixture['business']);

        $date = Carbon::parse($fixture['date'], 'Asia/Riyadh');
        $range = [
            'start' => $date->copy()->startOfDay()->toIso8601String(),
            'end' => $date->copy()->addDay()->startOfDay()->toIso8601String(),
            'timezone' => 'Asia/Riyadh',
        ];

        $calendar = Livewire::test(BookingCalendarWidget::class)
            ->set('filters.staff_id', 'none')
            ->call('fetchEvents', $range)
            ->assertReturned(function (array $events) use ($booking): bool {
                return count($events) === 1
                    && $events[0]['id'] === (string) $booking->getKey()
                    && $events[0]['extendedProps']['staffless'] === true
                    && ! array_key_exists('customer', $events[0])
                    && ! str_contains($events[0]['title'], 'Staffless Customer');
            });

        $actionModalId = $calendar->instance()->getId().'-action';

        $calendar->call('onEventClick', ['id' => (string) $booking->getKey()])
            ->assertSet('mountedActions.0', 'view');

        $this->assertTrue($calendar->instance()->mountedActionShouldOpenModal());
        $calendar
            ->assertDispatched('open-modal', id: $actionModalId)
            ->assertSee('Booking Reference: '.$booking->reference)
            ->assertSee('No staff required');

        $calendar
            ->call('mountAction', 'reschedule')
            ->assertSet('mountedActions.1', 'reschedule')
            ->assertDontSee('Select a location and service to load the appointment calendar.')
            ->assertSee('Appointment time')
            ->assertSee('Open')
            ->assertSee('8:00 AM');
    }

    public function test_reschedule_action_lists_staff_assigned_to_the_selected_authorized_location(): void
    {
        $fixture = $this->fixture(BookingMode::StaffRequired, 1);
        $staff = Staff::query()->create([
            'business_id' => $fixture['business']->getKey(),
            'name' => 'Eligible Reschedule Staff',
            'status' => 'active',
        ]);
        $staff->services()->attach($fixture['service']->getKey());
        $staff->locations()->attach($fixture['location']->getKey(), ['is_active' => true]);
        $booking = app(CreateBooking::class)->execute(
            $fixture['business'],
            $fixture['location'],
            $fixture['service'],
            $staff,
            $this->start($fixture, '09:00'),
            ['name' => 'Reschedule Customer', 'phone' => '0501000071'],
            null,
            false,
        )->booking;

        $owner = User::factory()->create();
        $fixture['business']->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner, 'web');
        Filament::setTenant($fixture['business']);

        Livewire::test(ListBookings::class)
            ->mountTableAction('reschedule', $booking)
            ->assertSet('mountedTableActions.0', 'reschedule')
            ->assertSee('Eligible Reschedule Staff')
            ->assertDontSee('Select a location and service to load the appointment calendar.')
            ->assertSee('Open')
            ->assertSee('8:00 AM');
    }

    public function test_bookings_page_switches_between_table_and_calendar_views(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 1);
        $owner = User::factory()->create();
        $fixture['business']->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner, 'web');
        Filament::setTenant($fixture['business']);

        Livewire::test(ListBookings::class)
            ->assertSet('displayMode', 'table')
            ->call('setDisplayMode', 'calendar')
            ->assertSet('displayMode', 'calendar')
            ->call('setDisplayMode', 'table')
            ->assertSet('displayMode', 'table');
    }

    public function test_staffless_booking_uses_location_calendar_and_peak_occupancy(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 2);
        $first = $this->create($fixture, '09:00', '0501000001');
        $second = $this->create($fixture, '10:00', '0501000002');

        $this->assertNull($first->booking->staff_id);
        $this->assertNull($second->booking->staff_id);

        $result = app(AvailabilityService::class)->forDate(
            $fixture['business'],
            $fixture['location'],
            $fixture['service'],
            $fixture['date'],
        );
        $slot = collect($result['slots'])->first(
            fn (array $candidate): bool => str_starts_with($candidate['start'], $fixture['date'].'T09:30:'),
        );

        $this->assertNotNull($slot, 'A non-overlapping pair of bookings must not be counted as simultaneous occupancy.');
        $this->assertSame(BookingMode::Staffless->value, $result['service']['booking_mode']);

        $third = $this->create($fixture, '09:30', '0501000003');
        $this->assertNull($third->booking->staff_id);
    }

    public function test_staffless_capacity_covers_buffered_occupancy_across_local_midnight(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 1);
        $calendar = $fixture['location']->calendar;
        $firstDate = Carbon::parse($fixture['date'], 'Asia/Riyadh');
        $nextDate = $firstDate->copy()->addDay();
        $calendar->rules()->where('day_of_week', $firstDate->dayOfWeek)->firstOrFail()->update([
            'start_time' => '22:00:00',
            'end_time' => '23:59:00',
        ]);
        $calendar->rules()->create([
            'day_of_week' => $nextDate->dayOfWeek,
            'start_time' => '00:00:00',
            'end_time' => '03:00:00',
        ]);
        $fixture['service']->update(['buffer_after_minutes' => 60]);

        $first = app(CreateBooking::class)->execute(
            $fixture['business'],
            $fixture['location'],
            $fixture['service'],
            null,
            new DateTimeImmutable($fixture['date'].'T22:30:00+03:00'),
            ['name' => 'Before Midnight Customer', 'phone' => '0501000051'],
            null,
            false,
        );
        $nextStart = new DateTimeImmutable($nextDate->toDateString().'T00:00:00+03:00');
        $availability = app(AvailabilityService::class)->forDate(
            $fixture['business'],
            $fixture['location'],
            $fixture['service'],
            $nextDate->toDateString(),
        );

        $midnightOption = collect($availability['slot_options'])->first(
            fn (array $option): bool => str_starts_with($option['start'], $nextDate->toDateString().'T00:00:'),
        );
        $this->assertNotNull($midnightOption);
        $this->assertFalse($midnightOption['available']);

        try {
            app(CreateBooking::class)->execute(
                $fixture['business'],
                $fixture['location'],
                $fixture['service'],
                null,
                $nextStart,
                ['name' => 'After Midnight Customer', 'phone' => '0501000052'],
                null,
                false,
            );
            $this->fail('The previous appointment buffer must consume capacity after midnight.');
        } catch (DomainException $exception) {
            $this->assertSame('SLOT_UNAVAILABLE', $exception->getMessage());
        }

        $this->assertSame($nextDate->toDateString(), $first->booking->occupied_ends_at->setTimezone(new DateTimeZone('Asia/Riyadh'))->format('Y-m-d'));
    }

    public function test_active_holds_consume_capacity_until_expired(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 1);
        $hold = $this->create($fixture, '09:00', '0501000011', hold: true);
        $this->assertSame(BookingStatus::Held, $hold->booking->status);

        $availability = app(AvailabilityService::class)->forDate(
            $fixture['business'],
            $fixture['location'],
            $fixture['service'],
            $fixture['date'],
        );
        $this->assertNotContains(
            $fixture['date'].'T09:00:00+03:00',
            array_column($availability['slots'], 'start'),
        );

        Carbon::setTestNow(now()->addMinutes(6));
        try {
            $availability = app(AvailabilityService::class)->forDate(
                $fixture['business'],
                $fixture['location'],
                $fixture['service'],
                $fixture['date'],
            );
            $this->assertContains(
                $fixture['date'].'T09:00:00+03:00',
                array_column($availability['slots'], 'start'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_staff_required_remains_the_default_and_requires_a_staff_member(): void
    {
        $fixture = $this->fixture(BookingMode::StaffRequired, 1);
        $this->assertSame(
            BookingMode::StaffRequired,
            ServiceLocation::query()
                ->where('service_id', $fixture['service']->getKey())
                ->where('location_id', $fixture['location']->getKey())
                ->firstOrFail()
                ->booking_mode,
        );

        try {
            $this->create($fixture, '09:00', '0501000021');
            $this->fail('A staff-required service must not accept a missing staff member.');
        } catch (DomainException $exception) {
            $this->assertSame('STAFF_REQUIRED', $exception->getMessage());
        }

        $staff = Staff::query()->create([
            'business_id' => $fixture['business']->getKey(),
            'name' => 'Staff Required',
            'status' => 'active',
        ]);
        $staff->services()->sync([$fixture['service']->getKey()]);
        $staff->locations()->sync([$fixture['location']->getKey() => ['is_active' => true]]);

        $result = app(CreateBooking::class)->execute(
            $fixture['business'],
            $fixture['location'],
            $fixture['service'],
            $staff,
            $this->start($fixture, '09:00'),
            ['name' => 'Staff Customer', 'phone' => '0501000022'],
            null,
            false,
        );

        $this->assertSame($staff->getKey(), $result->booking->staff_id);
    }

    public function test_mode_change_and_capacity_reduction_respect_active_occupancy(): void
    {
        $fixture = $this->fixture(BookingMode::Staffless, 2);
        $this->create($fixture, '09:00', '0501000031');
        $this->create($fixture, '09:30', '0501000032');

        try {
            app(UpdateServiceLocationSettings::class)->execute(
                $fixture['business'],
                $fixture['service'],
                $fixture['location'],
                BookingMode::Staffless,
                1,
            );
            $this->fail('Capacity cannot be reduced below current peak occupancy.');
        } catch (DomainException $exception) {
            $this->assertSame('CAPACITY_BELOW_CURRENT_OCCUPANCY', $exception->getMessage());
        }

        try {
            app(UpdateServiceLocationSettings::class)->execute(
                $fixture['business'],
                $fixture['service'],
                $fixture['location'],
                BookingMode::StaffRequired,
                2,
            );
            $this->fail('Booking mode cannot change while active future bookings exist.');
        } catch (DomainException $exception) {
            $this->assertSame('BOOKING_MODE_CHANGE_BLOCKED', $exception->getMessage());
        }
    }

    private function fixture(BookingMode $mode, int $capacity): array
    {
        $business = Business::query()->create([
            'name' => 'Staffless Business',
            'slug' => 'staffless-'.Str::lower(Str::random(12)),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $calendar = AvailabilityCalendar::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Location Calendar',
            'timezone' => 'Asia/Riyadh',
        ]);
        $date = now('Asia/Riyadh')->addDays(7)->toDateString();
        $calendar->rules()->create([
            'day_of_week' => (int) (new DateTimeImmutable($date, new DateTimeZone('Asia/Riyadh')))->format('w'),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);
        $location = Location::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $calendar->getKey(),
            'name' => 'Staffless Branch',
            'slug' => 'staffless-'.Str::lower(Str::random(12)),
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);
        $service = Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Capacity Service',
            'slug' => 'capacity-'.Str::lower(Str::random(12)),
            'duration_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 30,
            'price_minor' => 1000,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
        $location->services()->sync([
            $service->getKey() => ['booking_mode' => $mode->value, 'capacity' => $capacity],
        ]);

        return compact('business', 'location', 'service', 'date');
    }

    private function create(array $fixture, string $time, string $phone, bool $hold = false): BookingCreationResult
    {
        return app(CreateBooking::class)->execute(
            $fixture['business'],
            $fixture['location'],
            $fixture['service'],
            null,
            $this->start($fixture, $time),
            ['name' => 'Staffless Customer', 'phone' => $phone],
            null,
            $hold,
        );
    }

    private function start(array $fixture, string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($fixture['date'].'T'.$time.':00+03:00');
    }
}
