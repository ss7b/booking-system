<?php

namespace Tests\Feature;

use App\Domain\Booking\Services\BookingManagementLink;
use App\Domain\Booking\Services\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\BusinessResource\Pages\ManageBusinesses;
use App\Models\AvailabilityCalendar;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class BookingManagementLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_a_business_bound_link_and_allows_booking_details(): void
    {
        $fixture = $this->makeBookingFixture();
        $fixture['business']->forceFill([
            'metadata' => [
                'booking_management_url' => 'https://clinic.example/manage-booking?business=spoof&reference=spoof&campaign=spring',
            ],
        ])->save();
        $url = app(BookingManagementLink::class)->createUrl($fixture['booking']);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://clinic.example/manage-booking?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($fixture['business']->slug, $query['business']);
        $this->assertSame($fixture['booking']->reference, $query['reference']);
        $this->assertSame('spring', $query['campaign']);
        $this->assertStringNotContainsString('access_token', $url);

        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, $this->managementHeaders($fixture, $this->tokenFromUrl($url)))
            ->assertOk()
            ->assertJsonPath('data.reference', $fixture['booking']->reference)
            ->assertJsonPath('data.service_id', $fixture['service']->getKey())
            ->assertJsonPath('data.staff_id', $fixture['staff']->getKey())
            ->assertJsonPath('data.location_id', $fixture['location']->getKey())
            ->assertJsonPath('data.timezone', $fixture['location']->timezone ?: $fixture['business']->timezone);

        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, [
            'X-Business-Slug' => $fixture['business']->slug,
        ])->assertNotFound()
            ->assertJsonMissingPath('data.service_id')
            ->assertJsonMissingPath('data.staff_id')
            ->assertJsonMissingPath('data.location_id');

        $this->postJson('/api/v1/bookings/'.$fixture['booking']->reference.'/confirm', [], [
            ...$this->managementHeaders($fixture, $this->tokenFromUrl($url)),
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertNotFound();
    }

    public function test_it_rejects_tampered_expired_and_cross_business_links(): void
    {
        $fixture = $this->makeBookingFixture();
        $service = app(BookingManagementLink::class);
        $url = $service->createUrl($fixture['booking']);
        $token = $this->tokenFromUrl($url);
        $tampered = substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a');

        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, $this->managementHeaders($fixture, $tampered))
            ->assertNotFound()
            ->assertJsonPath('code', 'BOOKING_MANAGEMENT_LINK_INVALID')
            ->assertJsonMissingPath('data.reference');

        $otherBusiness = $this->makeBusiness();
        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, [
            'X-Business-Slug' => $otherBusiness->slug,
            'X-Booking-Management-Token' => $token,
        ])->assertNotFound();

        $this->travelTo($fixture['booking']->ends_at);
        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, $this->managementHeaders($fixture, $token))
            ->assertGone()
            ->assertJsonPath('code', 'BOOKING_MANAGEMENT_LINK_EXPIRED');
    }

    public function test_it_allows_view_only_from_start_until_end(): void
    {
        $fixture = $this->makeBookingFixture();
        $token = $this->tokenFromUrl(app(BookingManagementLink::class)->createUrl($fixture['booking']));
        $headers = $this->managementHeaders($fixture, $token);
        $this->travelTo($fixture['booking']->starts_at);

        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, $headers)->assertOk();
        $this->postJson('/api/v1/bookings/'.$fixture['booking']->reference.'/cancel', [
            'reason' => 'No longer needed',
        ], $headers)->assertStatus(409)->assertJsonPath('code', 'BOOKING_MANAGEMENT_READ_ONLY');

        $this->postJson('/api/v1/bookings/'.$fixture['booking']->reference.'/reschedule', [
            'start' => $fixture['booking']->starts_at->addHour()->format(DATE_ATOM),
        ], $headers)->assertStatus(409)->assertJsonPath('code', 'BOOKING_MANAGEMENT_READ_ONLY');
    }

    public function test_booking_details_ids_load_branch_availability_without_offering_the_current_slot(): void
    {
        $fixture = $this->makeBookingFixture();
        $token = $this->tokenFromUrl(app(BookingManagementLink::class)->createUrl($fixture['booking']));
        $headers = $this->managementHeaders($fixture, $token);
        $date = $fixture['booking']->starts_at->setTimezone('Asia/Riyadh')->format('Y-m-d');

        $details = $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, $headers)
            ->assertOk()
            ->json('data');

        $availability = $this->getJson('/api/v1/availability?'.http_build_query([
            'location_id' => $details['location_id'],
            'service_id' => $details['service_id'],
            'staff_id' => $details['staff_id'],
            'date' => $date,
        ]), ['X-Business-Slug' => $fixture['business']->slug])
            ->assertOk()
            ->json('data');

        $currentSlot = collect($availability['slot_options'])->first(
            fn (array $slot): bool => (new DateTimeImmutable($slot['start']))->getTimestamp()
                === $fixture['booking']->starts_at->getTimestamp(),
        );
        $this->assertNotNull($currentSlot);
        $this->assertFalse($currentSlot['available']);
        $this->assertNotEmpty(array_filter($availability['slots']));

        $monthStart = $fixture['booking']->starts_at->setTimezone('Asia/Riyadh')->startOfMonth()->format('Y-m-d');
        $monthEnd = $fixture['booking']->starts_at->setTimezone('Asia/Riyadh')->endOfMonth()->format('Y-m-d');
        $calendar = $this->getJson('/api/v1/availability/calendar?'.http_build_query([
            'location_id' => $details['location_id'],
            'service_id' => $details['service_id'],
            'staff_id' => $details['staff_id'],
            'from' => $monthStart,
            'until' => $monthEnd,
        ]), ['X-Business-Slug' => $fixture['business']->slug])
            ->assertOk()
            ->json('data');

        $this->assertTrue(collect($calendar)->firstWhere('date', $date)['available']);
    }

    public function test_cancellation_revokes_the_management_link_but_regular_access_tokens_still_work(): void
    {
        $fixture = $this->makeBookingFixture();
        $token = $this->tokenFromUrl(app(BookingManagementLink::class)->createUrl($fixture['booking']));
        $headers = $this->managementHeaders($fixture, $token) + ['Idempotency-Key' => (string) Str::uuid()];

        $this->postJson('/api/v1/bookings/'.$fixture['booking']->reference.'/cancel', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', BookingStatus::Cancelled->value);

        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, $headers)
            ->assertGone()
            ->assertJsonPath('code', 'BOOKING_MANAGEMENT_LINK_REVOKED');

        $accessToken = 'existing-booking-access-token';
        $fixture['booking']->forceFill(['access_token_hash' => Hash::make($accessToken)])->save();
        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, [
            'X-Business-Slug' => $fixture['business']->slug,
            'X-Booking-Access-Token' => $accessToken,
        ])->assertOk();
    }

    public function test_public_rescheduling_preserves_booking_choices_and_reissues_a_link(): void
    {
        $fixture = $this->makeBookingFixture();
        $service = app(BookingManagementLink::class);
        $oldToken = $this->tokenFromUrl($service->createUrl($fixture['booking']));
        $newStart = $fixture['booking']->starts_at->addMinutes(30);
        $headers = $this->managementHeaders($fixture, $oldToken) + ['Idempotency-Key' => (string) Str::uuid()];

        $this->postJson('/api/v1/bookings/'.$fixture['booking']->reference.'/reschedule', [
            'staff_id' => (string) Str::ulid(),
            'location_id' => (string) Str::ulid(),
            'service_id' => (string) Str::ulid(),
            'start' => $newStart->format(DATE_ATOM),
        ], $headers)->assertUnprocessable();

        $this->assertTrue($fixture['booking']->fresh()->starts_at->equalTo($fixture['start']));

        $response = $this->postJson('/api/v1/bookings/'.$fixture['booking']->reference.'/reschedule', [
            'start' => $newStart->format(DATE_ATOM),
        ], $headers)->assertOk();

        $newUrl = $response->json('data.management_url');
        $this->assertIsString($newUrl);
        $newToken = $this->tokenFromUrl($newUrl);
        $this->assertNotSame($oldToken, $newToken);

        $updated = $fixture['booking']->fresh();
        $this->assertSame($fixture['service']->getKey(), $updated->service_id);
        $this->assertSame($fixture['staff']->getKey(), $updated->staff_id);
        $this->assertSame($fixture['location']->getKey(), $updated->location_id);
        $this->assertSame(1, $updated->metadata['booking_management_version']);

        $this->getJson('/api/v1/bookings/'.$updated->reference, $this->managementHeaders($fixture, $oldToken))
            ->assertGone()
            ->assertJsonPath('code', 'BOOKING_MANAGEMENT_LINK_REVOKED');
        $this->getJson('/api/v1/bookings/'.$updated->reference, $this->managementHeaders($fixture, $newToken))
            ->assertOk();

        $this->travelTo($updated->ends_at);
        $this->getJson('/api/v1/bookings/'.$updated->reference, $this->managementHeaders($fixture, $newToken))
            ->assertGone()
            ->assertJsonPath('code', 'BOOKING_MANAGEMENT_LINK_EXPIRED');
    }

    public function test_rescheduling_through_the_shared_filament_service_revokes_old_links(): void
    {
        $fixture = $this->makeBookingFixture();
        $oldToken = $this->tokenFromUrl(app(BookingManagementLink::class)->createUrl($fixture['booking']));

        app(RescheduleBooking::class)->execute(
            $fixture['business'],
            $fixture['booking'],
            $fixture['location'],
            $fixture['staff'],
            new DateTimeImmutable($fixture['booking']->starts_at->addMinutes(30)->format(DATE_ATOM)),
            'user',
            null,
        );

        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, $this->managementHeaders($fixture, $oldToken))
            ->assertGone()
            ->assertJsonPath('code', 'BOOKING_MANAGEMENT_LINK_REVOKED');
    }

    public function test_admin_api_rescheduling_revokes_the_previous_management_link(): void
    {
        $fixture = $this->makeBookingFixture();
        $oldToken = $this->tokenFromUrl(app(BookingManagementLink::class)->createUrl($fixture['booking']));
        $owner = User::factory()->create();
        $fixture['business']->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/admin/bookings/'.$fixture['booking']->getKey().'/reschedule', [
            'location_id' => $fixture['location']->getKey(),
            'staff_id' => $fixture['staff']->getKey(),
            'start' => $fixture['start']->addMinutes(30)->format(DATE_ATOM),
        ], [
            'X-Business-Slug' => $fixture['business']->slug,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();

        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, $this->managementHeaders($fixture, $oldToken))
            ->assertGone()
            ->assertJsonPath('code', 'BOOKING_MANAGEMENT_LINK_REVOKED');
    }

    public function test_filament_rescheduling_revokes_the_previous_management_link(): void
    {
        $fixture = $this->makeBookingFixture();
        $oldToken = $this->tokenFromUrl(app(BookingManagementLink::class)->createUrl($fixture['booking']));
        $owner = User::factory()->create();
        $fixture['business']->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Livewire::actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($fixture['business']);

        Livewire::test(ListBookings::class)
            ->callTableAction('reschedule', $fixture['booking'], [
                'location_id' => $fixture['location']->getKey(),
                'staff_id' => $fixture['staff']->getKey(),
                'starts_at' => $fixture['start']->addMinutes(30)->format('Y-m-d H:i:s'),
                'reason' => 'Customer requested a new time.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($fixture['booking']->fresh()->starts_at->greaterThan($fixture['booking']->starts_at));
        $this->getJson('/api/v1/bookings/'.$fixture['booking']->reference, $this->managementHeaders($fixture, $oldToken))
            ->assertGone()
            ->assertJsonPath('code', 'BOOKING_MANAGEMENT_LINK_REVOKED');
    }

    public function test_link_generation_requires_a_confirmed_booking_and_valid_business_url(): void
    {
        $fixture = $this->makeBookingFixture();
        $service = app(BookingManagementLink::class);
        $fixture['booking']->status = BookingStatus::Held;
        $fixture['booking']->save();
        $this->assertNull($service->createUrl($fixture['booking']));

        $this->assertTrue(BookingManagementLink::isValidManagementUrl('https://clinic.example/manage-booking'));
        $this->assertFalse(BookingManagementLink::isValidManagementUrl('javascript:alert(1)'));
        $this->assertFalse(BookingManagementLink::isValidManagementUrl('http://clinic.example/manage-booking'));
        $this->assertTrue(BookingManagementLink::isValidManagementUrl('http://localhost/manage-booking'));
        config(['app.env' => 'production']);
        $this->assertFalse(BookingManagementLink::isValidManagementUrl('http://localhost/manage-booking'));
    }

    public function test_management_token_header_is_allowed_by_cross_origin_preflight(): void
    {
        $this->withHeaders([
            'Origin' => 'https://wordpress.example',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'x-business-slug,x-booking-management-token',
        ])->options('/api/v1/bookings/example')
            ->assertNoContent()
            ->assertHeaderContains('Access-Control-Allow-Headers', 'x-booking-management-token');
    }

    public function test_filament_saves_the_booking_management_url_in_business_metadata(): void
    {
        $business = $this->makeBusiness();
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Livewire::actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($business);

        Livewire::test(ManageBusinesses::class)
            ->callTableAction('edit', $business, [
                'name' => $business->name,
                'slug' => $business->slug,
                'timezone' => $business->timezone,
                'default_currency' => $business->default_currency,
                'is_active' => true,
                'booking_management_url' => 'https://clinic.example/manage-booking',
                'metadata' => ['external_crm_id' => 'crm-123'],
            ]);

        $this->assertSame([
            'external_crm_id' => 'crm-123',
            'booking_management_url' => 'https://clinic.example/manage-booking',
        ], $business->fresh()->metadata);
    }

    public function test_filament_rejects_non_https_management_urls_in_production(): void
    {
        config(['app.env' => 'production']);
        $business = $this->makeBusiness();
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);
        Livewire::actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($business);

        Livewire::test(ManageBusinesses::class)
            ->callTableAction('edit', $business, [
                'name' => $business->name,
                'slug' => $business->slug,
                'timezone' => $business->timezone,
                'default_currency' => $business->default_currency,
                'is_active' => true,
                'booking_management_url' => 'http://clinic.example/manage-booking',
                'metadata' => [],
            ])
            ->assertHasTableActionErrors(['booking_management_url']);

        $this->assertArrayNotHasKey('booking_management_url', $business->fresh()->metadata ?? []);
    }

    private function makeBookingFixture(): array
    {
        $start = CarbonImmutable::now('Asia/Riyadh')->addDays(7)->setTime(10, 0);
        $business = $this->makeBusiness('https://clinic.example/manage-booking');
        $calendar = AvailabilityCalendar::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Management Link Calendar',
            'timezone' => 'Asia/Riyadh',
        ]);
        $calendar->rules()->create([
            'day_of_week' => $start->dayOfWeek,
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
            'name' => 'Consultation',
            'slug' => 'consultation-'.Str::random(6),
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'slot_interval_minutes' => 15,
            'min_notice_minutes' => 0,
            'max_advance_days' => 30,
            'is_active' => true,
        ]);
        $staff = Staff::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $calendar->getKey(),
            'name' => 'Staff Member',
            'slug' => 'staff-'.Str::random(6),
            'status' => 'active',
        ]);
        $staff->services()->sync([$service->getKey()]);
        $staff->locations()->sync([$location->getKey() => ['is_active' => true]]);
        $location->services()->sync([$service->getKey()]);
        $customer = Customer::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Booking Customer',
            'phone' => '+966500000123',
        ]);

        $booking = Booking::query()->create([
            'business_id' => $business->getKey(),
            'location_id' => $location->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => 'BK-MANAGEMENT-'.Str::upper(Str::random(8)),
            'status' => BookingStatus::Confirmed,
            'source' => 'test',
            'starts_at' => $start->utc(),
            'ends_at' => $start->addMinutes(30)->utc(),
            'occupied_starts_at' => $start->utc(),
            'occupied_ends_at' => $start->addMinutes(30)->utc(),
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => $service->duration_minutes,
            'metadata' => ['customer_contact' => ['phone' => '+966500000123']],
        ]);

        return compact('business', 'calendar', 'location', 'service', 'staff', 'customer', 'booking', 'start');
    }

    private function makeBusiness(?string $managementUrl = null): Business
    {
        return Business::query()->create([
            'name' => 'Management Link Business',
            'slug' => 'management-'.Str::random(8),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
            'metadata' => $managementUrl === null ? [] : ['booking_management_url' => $managementUrl],
        ]);
    }

    private function managementHeaders(array $fixture, string $token): array
    {
        return [
            'X-Business-Slug' => $fixture['business']->slug,
            'X-Booking-Management-Token' => $token,
        ];
    }

    private function tokenFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_FRAGMENT), $fragment);

        return $fragment['management_token'] ?? '';
    }
}
