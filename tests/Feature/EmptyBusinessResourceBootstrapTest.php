<?php

namespace Tests\Feature;

use App\Domain\Authorization\BusinessAccess;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Filament\Resources\AvailabilityCalendarResource;
use App\Filament\Resources\CalendarOverrideResource;
use App\Filament\Resources\CalendarRuleResource;
use App\Filament\Resources\LocationResource;
use App\Models\AvailabilityCalendar;
use App\Models\Business;
use App\Models\CalendarOverride;
use App\Models\CalendarRule;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class EmptyBusinessResourceBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUpTraits()
    {
        $this->assertSame('booking_test', DB::connection()->getDatabaseName());
        $this->assertSame('booking_test', DB::selectOne('select database() as name')->name);

        return parent::setUpTraits();
    }

    public function test_owner_can_open_empty_resources_and_sees_localized_bootstrap_states(): void
    {
        $business = $this->business('empty-business');
        $owner = $this->member($business, BusinessRole::Owner, BranchAccessScope::All);
        $owner->forceFill(['preferred_locale' => 'ar'])->save();
        $this->actingAs($owner, 'web');

        Filament::setTenant($business);
        foreach ([
            LocationResource::class => Location::class,
            AvailabilityCalendarResource::class => AvailabilityCalendar::class,
            CalendarRuleResource::class => CalendarRule::class,
            CalendarOverrideResource::class => CalendarOverride::class,
        ] as $resource => $model) {
            $this->assertTrue(Gate::forUser($owner)->allows('viewAny', $model), $resource);
        }

        $urls = [
            'locations' => $this->resourceUrl('locations', $business),
            'availability-calendars' => $this->resourceUrl('availability-calendars', $business),
            'calendar-rules' => $this->resourceUrl('calendar-rules', $business),
            'calendar-overrides' => $this->resourceUrl('calendar-overrides', $business),
        ];

        foreach ($urls as $resource => $url) {
            $response = $this->get($url)->assertOk();
            $response->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);

            foreach ($urls as $navigationUrl) {
                $response->assertSee($navigationUrl, false);
            }

            if ($resource === 'calendar-rules') {
                $response->assertSee(Lang::get('resources.calendar_rule.empty_state.no_calendar', [], 'ar'));
                $response->assertSee(Lang::get('resources.calendar_rule.empty_state.manage_calendars', [], 'ar'));
                $response->assertDontSee("wire:click=\"mountAction('create')\"", false);
            }

            if ($resource === 'calendar-overrides') {
                $response->assertSee(Lang::get('resources.calendar_override.empty_state.no_calendar', [], 'ar'));
                $response->assertSee(Lang::get('resources.calendar_override.empty_state.manage_calendars', [], 'ar'));
                $response->assertDontSee("wire:click=\"mountAction('create')\"", false);
            }

            if ($resource === 'locations') {
                $response->assertSee(Lang::get('resources.location.empty_state.description', [], 'ar'));
                $response->assertSee("mountTableAction('create')", false);
            }

            if ($resource === 'availability-calendars') {
                $response->assertSee(Lang::get('resources.availability_calendar.empty_state.description', [], 'ar'));
                $response->assertSee("mountTableAction('create')", false);
            }
        }

        $this->assertSame(
            'Create a calendar and link it to a location or staff member before adding rules.',
            Lang::get('resources.calendar_rule.empty_state.no_calendar', [], 'en'),
        );
        $this->assertSame(
            'أنشئ تقويمًا واربطه بفرع أو موظف قبل إضافة الاستثناءات.',
            Lang::get('resources.calendar_override.empty_state.no_calendar', [], 'ar'),
        );

        Filament::setTenant($business);
        $this->assertTrue(Gate::forUser($owner)->allows('create', Location::class));
        $this->assertTrue(Gate::forUser($owner)->allows('create', AvailabilityCalendar::class));
        $this->assertFalse(Gate::forUser($owner)->allows('create', CalendarRule::class));
        $this->assertFalse(Gate::forUser($owner)->allows('create', CalendarOverride::class));
    }

    public function test_english_empty_state_is_translated_and_left_to_right(): void
    {
        $business = $this->business('english-empty');
        $owner = $this->member($business, BusinessRole::Owner, BranchAccessScope::All);
        $owner->forceFill(['preferred_locale' => 'en'])->save();
        $this->actingAs($owner, 'web');

        $response = $this->get($this->resourceUrl('calendar-rules', $business))->assertOk();
        $response->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Create a calendar and link it to a location or staff member before adding rules.')
            ->assertSee('Manage availability calendars');
    }

    public function test_owner_can_create_location_then_calendar_link_it_and_add_rules_and_overrides(): void
    {
        $business = $this->business('location-first');
        $owner = $this->member($business, BusinessRole::Owner, BranchAccessScope::All);
        $this->actingAs($owner, 'web');

        $location = $this->createThroughHeaderAction(
            LocationResource::class,
            'locations',
            $business,
            'manage-locations',
            [
                'name' => 'First location',
                'slug' => 'first-location',
                'timezone' => 'UTC',
                'calendar_id' => null,
                'is_active' => true,
            ],
        );
        $this->assertDatabaseHas('locations', [
            'id' => $location->getKey(),
            'business_id' => $business->getKey(),
            'calendar_id' => null,
        ]);

        $calendar = $this->createThroughHeaderAction(
            AvailabilityCalendarResource::class,
            'availability-calendars',
            $business,
            'manage-availability-calendars',
            ['name' => 'First calendar', 'timezone' => 'UTC'],
        );
        $this->assertDatabaseHas('availability_calendars', [
            'id' => $calendar->getKey(),
            'business_id' => $business->getKey(),
        ]);

        Filament::setTenant($business);
        $this->assertTrue(Gate::forUser($owner)->allows('view', $calendar));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $calendar));
        $this->assertTrue(app(BusinessAccess::class)->calendarsForManagement($owner, $business)->whereKey($calendar->getKey())->exists());
        $this->assertFalse(app(BusinessAccess::class)->calendars($owner, $business)->whereKey($calendar->getKey())->exists());

        $this->editThroughTableAction(
            'locations',
            $business,
            'manage-locations',
            $location->getKey(),
            ['calendar_id' => $calendar->getKey()],
        );
        $this->assertSame((string) $calendar->getKey(), (string) $location->fresh()->calendar_id);
        $this->assertTrue(app(BusinessAccess::class)->calendars($owner, $business)->whereKey($calendar->getKey())->exists());

        $rule = $this->createThroughHeaderAction(
            CalendarRuleResource::class,
            'calendar-rules',
            $business,
            'manage-calendar-rules',
            [
                'calendar_id' => $calendar->getKey(),
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ],
        );
        $this->assertInstanceOf(CalendarRule::class, $rule);

        $override = $this->createThroughHeaderAction(
            CalendarOverrideResource::class,
            'calendar-overrides',
            $business,
            'manage-calendar-overrides',
            [
                'calendar_id' => $calendar->getKey(),
                'date' => '2030-01-02',
                'mode' => 'closed',
                'reason' => 'Holiday',
            ],
        );
        $this->assertInstanceOf(CalendarOverride::class, $override);
    }

    public function test_owner_can_manage_an_unlinked_calendar_before_creating_a_location(): void
    {
        $business = $this->business('calendar-first');
        $owner = $this->member($business, BusinessRole::Owner, BranchAccessScope::All);
        $this->actingAs($owner, 'web');

        $calendar = $this->createThroughHeaderAction(
            AvailabilityCalendarResource::class,
            'availability-calendars',
            $business,
            'manage-availability-calendars',
            ['name' => 'Unlinked calendar', 'timezone' => 'UTC'],
        );

        $page = $this->get($this->resourceUrl('availability-calendars', $business))->assertOk();
        $page->assertSee('Unlinked calendar');
        $snapshot = $this->snapshot($page, 'manage-availability-calendars');
        $edit = $this->livewire($snapshot, 'mountTableAction', ['edit', $calendar->getKey()])->assertOk();
        $this->assertSame(['edit'], $this->livewireState($edit)['mountedTableActions'][0]);
        $this->livewire($edit->json('components.0.snapshot'), 'callMountedTableAction', updates: [
            'mountedTableActionsData.0.name' => 'Renamed unlinked calendar',
        ])->assertOk();
        $this->assertSame('Renamed unlinked calendar', $calendar->fresh()->name);

        $location = $this->createThroughHeaderAction(
            LocationResource::class,
            'locations',
            $business,
            'manage-locations',
            [
                'name' => 'Linked later',
                'slug' => 'linked-later',
                'timezone' => 'UTC',
                'calendar_id' => $calendar->getKey(),
                'is_active' => true,
            ],
        );
        $this->assertSame((string) $calendar->getKey(), (string) $location->fresh()->calendar_id);
        $this->assertTrue(app(BusinessAccess::class)->calendars($owner, $business)->whereKey($calendar->getKey())->exists());
    }

    public function test_empty_business_access_does_not_expand_other_roles_or_calendar_scopes(): void
    {
        $business = $this->business('role-scope');
        $owner = $this->member($business, BusinessRole::Owner, BranchAccessScope::All);
        $manager = $this->member($business, BusinessRole::Manager, BranchAccessScope::Selected);
        $managerAll = $this->member($business, BusinessRole::Manager, BranchAccessScope::All);
        $receptionist = $this->member($business, BusinessRole::Receptionist, BranchAccessScope::Selected);
        $staff = $this->member($business, BusinessRole::Staff, BranchAccessScope::Selected);

        $this->actingAs($owner, 'web');
        Filament::setTenant($business);
        foreach ([$manager, $managerAll, $receptionist, $staff] as $user) {
            foreach ([Location::class, AvailabilityCalendar::class, CalendarRule::class, CalendarOverride::class] as $model) {
                $this->assertFalse(Gate::forUser($user)->allows('viewAny', $model));
            }
        }

        foreach ([$manager, $managerAll, $receptionist, $staff] as $user) {
            $this->actingAs($user, 'web');
            foreach (['locations', 'availability-calendars', 'calendar-rules', 'calendar-overrides'] as $resource) {
                $this->get($this->resourceUrl($resource, $business))->assertForbidden();
            }
        }

        $this->actingAs($owner, 'web');
        $location = $business->locations()->create([
            'name' => 'Manager branch',
            'slug' => 'manager-branch',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        $linkedCalendar = $business->availabilityCalendars()->create(['name' => 'Linked', 'timezone' => 'UTC']);
        $unlinkedCalendar = $business->availabilityCalendars()->create(['name' => 'Unlinked', 'timezone' => 'UTC']);
        $location->update(['calendar_id' => $linkedCalendar->getKey()]);
        DB::table('business_user_locations')->insert([
            'business_id' => $business->getKey(),
            'user_id' => $manager->getKey(),
            'location_id' => $location->getKey(),
            'created_at' => now(),
        ]);

        $access = app(BusinessAccess::class);
        foreach ([false, true] as $contextEnabled) {
            config(['booking.resource_authorization_display_context.enabled' => $contextEnabled]);
            Filament::setTenant($business);
            $managedCalendarIds = $access->calendarsForManagement($manager, $business)->pluck('id')->all();
            $this->assertContains($linkedCalendar->getKey(), $managedCalendarIds);
            $this->assertNotContains($unlinkedCalendar->getKey(), $managedCalendarIds);
            $this->assertFalse(Gate::forUser($manager)->allows('view', $unlinkedCalendar));
            $this->assertTrue(Gate::forUser($owner)->allows('view', $unlinkedCalendar));
        }

        $otherBusiness = $this->business('other-scope');
        $otherCalendar = $otherBusiness->availabilityCalendars()->create(['name' => 'Foreign', 'timezone' => 'UTC']);
        $foreignRule = $otherCalendar->rules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);
        $foreignOverride = $otherCalendar->overrides()->create([
            'date' => '2030-01-02',
            'mode' => 'closed',
        ]);
        $otherBusiness->users()->attach($owner, [
            'role' => BusinessRole::Owner->value,
            'access_scope' => BranchAccessScope::All->value,
        ]);

        Filament::setTenant($business);
        $this->assertFalse(Gate::forUser($owner)->allows('view', $otherCalendar));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $otherCalendar));
        $this->assertFalse(Gate::forUser($owner)->allows('view', $foreignRule));
        $this->assertFalse(Gate::forUser($owner)->allows('view', $foreignOverride));
        $this->assertFalse($access->calendarsForManagement($owner, $business)->whereKey($otherCalendar->getKey())->exists());
    }

    public function test_livewire_rejects_a_foreign_calendar_id_when_creating_a_location(): void
    {
        $business = $this->business('calendar-id-owner');
        $otherBusiness = $this->business('calendar-id-other');
        $owner = $this->member($business, BusinessRole::Owner, BranchAccessScope::All);
        $foreignCalendar = $otherBusiness->availabilityCalendars()->create(['name' => 'Foreign', 'timezone' => 'UTC']);
        $this->actingAs($owner, 'web');

        $page = $this->get($this->resourceUrl('locations', $business))->assertOk();
        $mounted = $this->livewire($this->snapshot($page, 'manage-locations'), 'mountAction', ['create'])->assertOk();
        $snapshot = $mounted->json('components.0.snapshot');

        $this->livewire($snapshot, 'callMountedAction', updates: [
            'mountedActionsData.0.name' => 'Tampered location',
            'mountedActionsData.0.slug' => 'tampered-location',
            'mountedActionsData.0.timezone' => 'UTC',
            'mountedActionsData.0.calendar_id' => $foreignCalendar->getKey(),
            'mountedActionsData.0.is_active' => true,
        ])->assertOk();

        $this->assertDatabaseMissing('locations', [
            'business_id' => $business->getKey(),
            'slug' => 'tampered-location',
        ]);
    }

    public function test_livewire_rejects_foreign_calendar_ids_for_rules_and_overrides(): void
    {
        $business = $this->business('child-calendar-owner');
        $otherBusiness = $this->business('child-calendar-other');
        $owner = $this->member($business, BusinessRole::Owner, BranchAccessScope::All);
        $localCalendar = $business->availabilityCalendars()->create(['name' => 'Local', 'timezone' => 'UTC']);
        $business->locations()->create([
            'name' => 'Local location',
            'slug' => 'local-location',
            'timezone' => 'UTC',
            'calendar_id' => $localCalendar->getKey(),
            'is_active' => true,
        ]);
        $foreignCalendar = $otherBusiness->availabilityCalendars()->create(['name' => 'Foreign', 'timezone' => 'UTC']);
        $this->actingAs($owner, 'web');

        foreach ([
            [
                'resource' => 'calendar-rules',
                'component' => 'manage-calendar-rules',
                'model' => CalendarRule::class,
                'data' => ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00'],
            ],
            [
                'resource' => 'calendar-overrides',
                'component' => 'manage-calendar-overrides',
                'model' => CalendarOverride::class,
                'data' => ['date' => '2030-01-02', 'mode' => 'closed'],
            ],
        ] as $case) {
            $page = $this->get($this->resourceUrl($case['resource'], $business))->assertOk();
            $mounted = $this->livewire(
                $this->snapshot($page, $case['component']),
                'mountAction',
                ['create'],
            )->assertOk();
            $this->assertSame(['create'], $this->livewireState($mounted)['mountedActions'][0]);
            $updates = ['mountedActionsData.0.calendar_id' => $foreignCalendar->getKey()];

            foreach ($case['data'] as $key => $value) {
                $updates['mountedActionsData.0.'.$key] = $value;
            }

            $this->livewire(
                $mounted->json('components.0.snapshot'),
                'callMountedAction',
                updates: $updates,
            )->assertOk();

            $this->assertDatabaseMissing($case['model'], [
                'calendar_id' => $foreignCalendar->getKey(),
            ]);
        }
    }

    private function business(string $slug): Business
    {
        return Business::query()->create([
            'name' => str($slug)->replace('-', ' ')->title(),
            'slug' => $slug,
            'timezone' => 'UTC',
        ]);
    }

    private function member(Business $business, BusinessRole $role, BranchAccessScope $scope): User
    {
        $user = User::factory()->create();
        $business->users()->attach($user, ['role' => $role->value, 'access_scope' => $scope->value]);

        return $user;
    }

    private function resourceUrl(string $resource, Business $business): string
    {
        return route("filament.admin.resources.$resource.index", ['tenant' => $business->getRouteKey()]);
    }

    private function createThroughHeaderAction(
        string $resource,
        string $routeResource,
        Business $business,
        string $componentSuffix,
        array $data,
    ): mixed {
        $page = $this->get($this->resourceUrl($routeResource, $business))->assertOk();
        $mounted = $this->livewire($this->snapshot($page, $componentSuffix), 'mountAction', ['create'])->assertOk();
        $this->assertSame(['create'], $this->livewireState($mounted)['mountedActions'][0]);
        $snapshot = $mounted->json('components.0.snapshot');
        $updates = [];

        foreach ($data as $key => $value) {
            $updates['mountedActionsData.0.'.$key] = $value;
        }

        $this->livewire($snapshot, 'callMountedAction', updates: $updates)->assertOk();

        $model = $resource::getModel();
        $query = $model::query();

        if (in_array($model, [CalendarRule::class, CalendarOverride::class], true)) {
            $query->where('calendar_id', $data['calendar_id']);
        } else {
            $query->where('business_id', $business->getKey());
        }

        foreach (['name', 'slug'] as $identityColumn) {
            if (isset($data[$identityColumn])) {
                $query->where($identityColumn, $data[$identityColumn]);
            }
        }

        return $query->latest('created_at')->firstOrFail();
    }

    private function editThroughTableAction(
        string $routeResource,
        Business $business,
        string $componentSuffix,
        string $recordId,
        array $data,
    ): void {
        $page = $this->get($this->resourceUrl($routeResource, $business))->assertOk();
        $mounted = $this->livewire(
            $this->snapshot($page, $componentSuffix),
            'mountTableAction',
            ['edit', $recordId],
        )->assertOk();
        $this->assertSame(['edit'], $this->livewireState($mounted)['mountedTableActions'][0]);
        $snapshot = $mounted->json('components.0.snapshot');
        $updates = [];

        foreach ($data as $key => $value) {
            $updates['mountedTableActionsData.0.'.$key] = $value;
        }

        $this->livewire($snapshot, 'callMountedTableAction', updates: $updates)->assertOk();
    }

    private function snapshot(TestResponse $response, string $componentSuffix): string
    {
        preg_match_all('/\bwire:snapshot="([^"]+)"/', $response->getContent(), $matches);

        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES, 'UTF-8');
            $memo = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo'];

            if (str_ends_with($memo['name'], $componentSuffix)) {
                return $snapshot;
            }
        }

        $this->fail("The Livewire component ending in [$componentSuffix] did not render.");
    }

    private function livewire(string $snapshot, ?string $method = null, array $params = [], array $updates = []): TestResponse
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => $method === null ? [] : [['path' => '', 'method' => $method, 'params' => $params]],
        ]]], ['X-Livewire' => 'true']);
    }

    private function livewireState(TestResponse $response): array
    {
        $snapshot = $response->json('components.0.snapshot');
        $this->assertIsString($snapshot);

        return json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
