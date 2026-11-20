<?php

namespace Tests\Feature;

use App\Domain\Booking\Services\CreateBooking;
use App\Enums\BookingMode;
use App\Filament\Resources\ServiceResource\Pages\ManageServices;
use App\Models\AvailabilityCalendar;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceLocation;
use App\Models\User;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceResourceBookingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_saves_service_and_different_booking_modes_for_multiple_locations(): void
    {
        [$business] = $this->filamentContext();
        $firstLocation = $this->makeLocation($business, 'First');
        $secondLocation = $this->makeLocation($business, 'Second');

        $component = Livewire::test(ManageServices::class);
        $component->mountAction('create');
        $defaultRows = $component->instance()->mountedActionsData[0]['location_settings'] ?? [];
        $firstRow = array_key_first($defaultRows) ?? 'first';
        $component
            ->setActionData([
                'name' => 'Consultation',
                'slug' => 'consultation',
                'description' => 'Initial consultation',
                'duration_minutes' => 60,
                'slot_interval_minutes' => 30,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'min_notice_minutes' => 0,
                'max_advance_days' => null,
                'price_minor' => 2500,
                'currency' => 'SAR',
                'is_active' => true,
                'location_settings' => [
                    $firstRow => [
                        'location_id' => $firstLocation->getKey(),
                        'booking_mode' => BookingMode::StaffRequired->value,
                        'capacity' => 1,
                    ],
                    'second' => [
                        'location_id' => $secondLocation->getKey(),
                        'booking_mode' => BookingMode::Staffless->value,
                        'capacity' => 3,
                    ],
                ],
            ]);
        $component->callMountedAction();

        $service = Service::query()->where('business_id', $business->getKey())->sole();
        $this->assertSame(
            BookingMode::StaffRequired,
            ServiceLocation::query()->where('service_id', $service->getKey())
                ->where('location_id', $firstLocation->getKey())->sole()->booking_mode,
        );
        $staffless = ServiceLocation::query()->where('service_id', $service->getKey())
            ->where('location_id', $secondLocation->getKey())->sole();
        $this->assertSame(BookingMode::Staffless, $staffless->booking_mode);
        $this->assertSame(3, $staffless->capacity);
    }

    public function test_create_rejects_a_location_from_another_business(): void
    {
        [$business] = $this->filamentContext();
        $otherBusiness = Business::query()->create([
            'name' => 'Other business',
            'slug' => 'other-'.Str::lower(Str::random(10)),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $otherLocation = $this->makeLocation($otherBusiness, 'Outside');
        $component = Livewire::test(ManageServices::class)->mountAction('create');
        $rowKey = array_key_first($component->instance()->mountedActionsData[0]['location_settings']);

        $component
            ->setActionData([
                'name' => 'Unauthorized service',
                'slug' => 'unauthorized-service',
                'duration_minutes' => 60,
                'slot_interval_minutes' => 30,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'min_notice_minutes' => 0,
                'is_active' => true,
                'location_settings' => [
                    $rowKey => [
                        'location_id' => $otherLocation->getKey(),
                        'booking_mode' => BookingMode::Staffless->value,
                        'capacity' => 1,
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasActionErrors();

        $this->assertDatabaseMissing('services', [
            'business_id' => $business->getKey(),
            'slug' => 'unauthorized-service',
        ]);
    }

    public function test_edit_keeps_other_location_settings_and_updates_the_selected_capacity(): void
    {
        [$business] = $this->filamentContext();
        $firstLocation = $this->makeLocation($business, 'First');
        $secondLocation = $this->makeLocation($business, 'Second');
        $thirdLocation = $this->makeLocation($business, 'Third');
        $service = $this->makeService($business);
        $firstLocation->services()->attach($service, [
            'booking_mode' => BookingMode::Staffless->value,
            'capacity' => 3,
        ]);
        $secondLocation->services()->attach($service, [
            'booking_mode' => BookingMode::StaffRequired->value,
            'capacity' => 2,
        ]);

        $component = Livewire::test(ManageServices::class)
            ->assertTableActionDoesNotExist('configure_location_booking')
            ->mountTableAction('edit', $service);
        $currentRows = $component->instance()->mountedTableActionsData[0]['location_settings'] ?? [];
        $rowKeys = array_values(array_keys($currentRows));
        $component
            ->setTableActionData([
                'name' => 'Updated service name',
                'location_settings' => [
                    $rowKeys[0] => [
                        'location_id' => $firstLocation->getKey(),
                        'booking_mode' => BookingMode::Staffless->value,
                        'capacity' => 5,
                    ],
                    $rowKeys[1] => [
                        'location_id' => $secondLocation->getKey(),
                        'booking_mode' => BookingMode::StaffRequired->value,
                        'capacity' => 2,
                    ],
                    'new-location' => [
                        'location_id' => $thirdLocation->getKey(),
                        'booking_mode' => BookingMode::Staffless->value,
                        'capacity' => 2,
                    ],
                ],
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('Updated service name', $service->fresh()->name);
        $this->assertSame(5, ServiceLocation::query()->where('service_id', $service->getKey())
            ->where('location_id', $firstLocation->getKey())->sole()->capacity);
        $this->assertSame(BookingMode::StaffRequired, ServiceLocation::query()
            ->where('service_id', $service->getKey())
            ->where('location_id', $secondLocation->getKey())->sole()->booking_mode);
        $this->assertSame(2, ServiceLocation::query()->where('service_id', $service->getKey())
            ->where('location_id', $secondLocation->getKey())->sole()->capacity);
        $this->assertDatabaseHas('service_locations', [
            'service_id' => $service->getKey(),
            'location_id' => $thirdLocation->getKey(),
            'booking_mode' => BookingMode::Staffless->value,
            'capacity' => 2,
        ]);
    }

    public function test_edit_can_remove_an_unused_location_without_changing_the_other_pivot(): void
    {
        [$business] = $this->filamentContext();
        $removedLocation = $this->makeLocation($business, 'Remove');
        $remainingLocation = $this->makeLocation($business, 'Remain');
        $service = $this->makeService($business);
        $removedLocation->services()->attach($service, [
            'booking_mode' => BookingMode::Staffless->value,
            'capacity' => 2,
        ]);
        $remainingLocation->services()->attach($service, [
            'booking_mode' => BookingMode::StaffRequired->value,
            'capacity' => 4,
        ]);

        $component = Livewire::test(ManageServices::class)->mountTableAction('edit', $service);
        $rows = $component->instance()->mountedTableActionsData[0]['location_settings'];
        $remainingRowKey = $this->repeaterKeyForLocation($rows, $remainingLocation->getKey());

        $component
            ->set('mountedTableActionsData.0.location_settings', [
                $remainingRowKey => $rows[$remainingRowKey],
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('service_locations', [
            'service_id' => $service->getKey(),
            'location_id' => $removedLocation->getKey(),
        ]);
        $remaining = ServiceLocation::query()
            ->where('service_id', $service->getKey())
            ->where('location_id', $remainingLocation->getKey())
            ->sole();
        $this->assertSame(BookingMode::StaffRequired, $remaining->booking_mode);
        $this->assertSame(4, $remaining->capacity);
    }

    public function test_edit_loads_saved_settings_and_rejects_mode_change_with_an_active_booking(): void
    {
        [$business] = $this->filamentContext();
        $location = $this->makeLocation($business, 'Booked', true);
        $otherLocation = $this->makeLocation($business, 'Other');
        $service = $this->makeService($business);
        $location->services()->attach($service, [
            'booking_mode' => BookingMode::Staffless->value,
            'capacity' => 2,
        ]);
        $otherLocation->services()->attach($service, [
            'booking_mode' => BookingMode::StaffRequired->value,
            'capacity' => 1,
        ]);
        $date = now('Asia/Riyadh')->addDays(7)->toDateString();
        app(CreateBooking::class)->execute(
            $business,
            $location,
            $service,
            null,
            new DateTimeImmutable($date.'T09:00:00+03:00'),
            ['name' => 'Future customer', 'phone' => '0501000999'],
            null,
            false,
        );

        $component = Livewire::test(ManageServices::class)->mountTableAction('edit', $service);
        $rows = $component->instance()->mountedTableActionsData[0]['location_settings'];
        $bookedRowKey = $this->repeaterKeyForLocation($rows, $location->getKey());
        $otherRowKey = $this->repeaterKeyForLocation($rows, $otherLocation->getKey());
        $this->assertSame(BookingMode::Staffless->value, $rows[$bookedRowKey]['booking_mode']);
        $this->assertSame(2, $rows[$bookedRowKey]['capacity']);

        $component
            ->set('mountedTableActionsData.0.location_settings', [
                $otherRowKey => $rows[$otherRowKey],
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors();

        $this->assertSame(2, ServiceLocation::query()->where('service_id', $service->getKey())->count());

        $component->unmountTableAction()->mountTableAction('edit', $service);
        $rows = $component->instance()->mountedTableActionsData[0]['location_settings'];
        $bookedRowKey = $this->repeaterKeyForLocation($rows, $location->getKey());
        $otherRowKey = $this->repeaterKeyForLocation($rows, $otherLocation->getKey());
        $component
            ->setTableActionData([
                'location_settings' => [
                    $bookedRowKey => [
                        'location_id' => $location->getKey(),
                        'booking_mode' => BookingMode::StaffRequired->value,
                        'capacity' => 2,
                    ],
                    $otherRowKey => $rows[$otherRowKey],
                ],
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors();

        $assignment = ServiceLocation::query()
            ->where('service_id', $service->getKey())
            ->where('location_id', $location->getKey())
            ->sole();
        $this->assertSame(BookingMode::Staffless, $assignment->booking_mode);
        $this->assertSame(2, $assignment->capacity);
    }

    private function filamentContext(): array
    {
        $business = Business::query()->create([
            'name' => 'Service settings business',
            'slug' => 'service-settings-'.Str::lower(Str::random(10)),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'access_scope' => 'all']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($owner, 'web');
        Filament::setTenant($business);

        return [$business, $owner];
    }

    private function makeLocation(Business $business, string $suffix, bool $withCalendar = false): Location
    {
        $calendar = null;
        if ($withCalendar) {
            $calendar = AvailabilityCalendar::query()->create([
                'business_id' => $business->getKey(),
                'name' => $suffix.' calendar',
                'timezone' => 'Asia/Riyadh',
            ]);
            $date = now('Asia/Riyadh')->addDays(7);
            $calendar->rules()->create([
                'day_of_week' => (int) $date->format('w'),
                'start_time' => '08:00:00',
                'end_time' => '12:00:00',
            ]);
        }

        return Location::query()->create([
            'business_id' => $business->getKey(),
            'calendar_id' => $calendar?->getKey(),
            'name' => $suffix.' location',
            'slug' => Str::lower($suffix).'-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);
    }

    private function makeService(Business $business): Service
    {
        return Service::query()->create([
            'business_id' => $business->getKey(),
            'name' => 'Existing service',
            'slug' => 'existing-'.Str::lower(Str::random(8)),
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
    }

    private function repeaterKeyForLocation(array $rows, string $locationId): string
    {
        foreach ($rows as $key => $row) {
            if ((string) ($row['location_id'] ?? '') === $locationId) {
                return (string) $key;
            }
        }

        $this->fail("No repeater row found for location {$locationId}.");
    }
}
