<?php

namespace Tests\Feature;

use App\Domain\Authorization\ManageBusinessMemberships;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResourceDisplayAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private const PAGES = [
        'calendar_rules' => ['calendar-rules', 'manage-calendar-rules'],
        'customers' => ['customers', 'manage-customers'],
        'services' => ['services', 'manage-services'],
        'staff' => ['staff', 'manage-staff'],
    ];

    protected function setUpTraits()
    {
        $this->assertSame('booking_test', DB::connection()->getDatabaseName());
        $this->assertSame('booking_test', DB::selectOne('select database() as name')->name);

        return parent::setUpTraits();
    }

    public static function resources(): array
    {
        return [
            'calendar rules' => ['calendar_rules'],
            'customers' => ['customers'],
            'services' => ['services'],
            'staff' => ['staff'],
        ];
    }

    #[DataProvider('resources')]
    public function test_list_rows_and_action_authorization_match_with_context_on_and_off(string $resource): void
    {
        $fixture = $this->fixture('primary');
        $other = $this->fixture('other');
        $actors = [
            'owner' => [BusinessRole::Owner, BranchAccessScope::All],
            'manager all' => [BusinessRole::Manager, BranchAccessScope::All],
            'manager selected' => [BusinessRole::Manager, BranchAccessScope::Selected],
            'receptionist all' => [BusinessRole::Receptionist, BranchAccessScope::All],
            'receptionist selected' => [BusinessRole::Receptionist, BranchAccessScope::Selected],
            'linked staff' => [BusinessRole::Staff, BranchAccessScope::Selected],
            'unlinked staff' => [BusinessRole::Staff, BranchAccessScope::All],
            'no membership' => [null, null],
        ];

        foreach ($actors as $label => [$role, $scope]) {
            $user = User::factory()->create();
            if ($role !== null) {
                $this->saveMembership($fixture, $user, $role, $scope);
            }
            if ($label === 'linked staff') {
                $fixture['records']['staff']['north']->update(['user_id' => $user->getKey()]);
            }
            $this->actingAs($user, 'web');
            $expectedRows = $this->expectedRows($resource, $label);
            $canCreate = $resource !== 'customers' && ($role?->canManageOperations() ?? false);
            $membershipQueries = [];

            foreach ([false, true] as $enabled) {
                $this->feature($resource, $enabled);
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $page = $this->get($this->pageUrl($resource, $fixture['business']));
                    $membershipQueries[] = count(array_filter(
                        DB::getQueryLog(),
                        static fn (array $entry): bool => preg_match('/\bfrom\s+\x60?business_user\x60?(?=\s|$)/i', $entry['query']) === 1,
                    ));
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }

                if ($expectedRows === null) {
                    $page->assertForbidden();
                } else {
                    $this->assertRenderedRows($page->assertOk(), $fixture['records'][$resource], $expectedRows, $canCreate);
                }

                Filament::setTenant($fixture['business']);
                $records = $fixture['records'][$resource];
                $this->assertSame($canCreate, Gate::forUser($user)->allows('create', $records['north']::class), "$resource: $label create");
                $this->assertRecordPolicies($resource, $label, $user, $records, $expectedRows ?? []);

                foreach (['view', 'update', 'delete'] as $ability) {
                    $this->assertFalse(Gate::forUser($user)->allows($ability, $other['records'][$resource]['north']));
                }
            }

            if ($label === 'owner') {
                $this->assertGreaterThan(1, $membershipQueries[0], "$resource OFF baseline should expose repeated membership reads");
                $this->assertSame(1, $membershipQueries[1], "$resource should load membership once per HTTP request");
            }
        }
    }

    public function test_one_resource_can_be_disabled_without_disabling_the_shared_context(): void
    {
        $fixture = $this->fixture('feature-override');
        $user = User::factory()->create();
        $this->saveMembership($fixture, $user, BusinessRole::Owner, BranchAccessScope::All);
        $this->actingAs($user, 'web');
        config([
            'booking.resource_authorization_display_context.enabled' => true,
            'booking.resource_authorization_display_context.disabled_resources' => ['services' => true],
        ]);

        $membershipQueries = [];
        foreach (['customers', 'services'] as $resource) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->get($this->pageUrl($resource, $fixture['business']))->assertOk();
                $membershipQueries[$resource] = count(array_filter(
                    DB::getQueryLog(),
                    static fn (array $entry): bool => preg_match('/\bfrom\s+\x60?business_user\x60?(?=\s|$)/i', $entry['query']) === 1,
                ));
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }

        $this->assertSame(1, $membershipQueries['customers']);
        $this->assertGreaterThan(1, $membershipQueries['services']);
    }

    #[DataProvider('resources')]
    public function test_switching_tenants_does_not_allow_livewire_to_open_a_foreign_or_branch_excluded_record(string $resource): void
    {
        $this->feature($resource, true);
        $first = $this->fixture('first');
        $second = $this->fixture('second');
        $user = User::factory()->create();
        $this->saveMembership($first, $user, BusinessRole::Manager, BranchAccessScope::Selected);
        $this->saveMembership($second, $user, BusinessRole::Owner, BranchAccessScope::All);
        $this->actingAs($user, 'web');

        $firstUrl = $this->pageUrl($resource, $first['business']);
        $secondUrl = $this->pageUrl($resource, $second['business']);
        $firstPage = $this->get($firstUrl)->assertOk();
        $this->assertRenderedRows($firstPage, $first['records'][$resource], $this->expectedRows($resource, 'manager selected'), $resource !== 'customers');
        $firstSnapshot = $this->snapshot($firstPage, $resource);
        $this->assertRenderedRows($this->get($secondUrl)->assertOk(), $second['records'][$resource], $this->expectedRows($resource, 'owner'), $resource !== 'customers');

        foreach ([$first['records'][$resource]['south'], $second['records'][$resource]['north']] as $excluded) {
            $response = $this->livewire($firstSnapshot, 'mountTableAction', ['edit', $excluded->getKey()])->assertOk();
            $this->assertSame([], $this->livewireState($response)['mountedTableActions'][0]);
        }

        $this->assertRenderedRows($this->get($firstUrl)->assertOk(), $first['records'][$resource], $this->expectedRows($resource, 'manager selected'), $resource !== 'customers');
        $onlySecond = User::factory()->create();
        $this->saveMembership($second, $onlySecond, BusinessRole::Owner, BranchAccessScope::All);
        $this->actingAs($onlySecond, 'web')->get($firstUrl)->assertNotFound();
        $this->get($secondUrl)->assertOk();
    }

    #[DataProvider('resources')]
    public function test_livewire_checks_role_branch_and_membership_again_when_submitting_a_mounted_edit(string $resource): void
    {
        $this->feature($resource, true);
        $first = $this->fixture('first');
        $second = $this->fixture('second');
        $user = User::factory()->create();
        $this->saveMembership($first, $user, BusinessRole::Manager, BranchAccessScope::Selected);
        $this->saveMembership($second, $user, BusinessRole::Owner, BranchAccessScope::All);
        $first['records']['staff']['north']->update(['user_id' => $user->getKey()]);
        $this->actingAs($user, 'web');
        $url = $this->pageUrl($resource, $first['business']);
        $record = $first['records'][$resource]['north'];
        $page = $this->snapshot($this->get($url)->assertOk(), $resource);
        $mounted = $this->livewire($page, 'mountTableAction', ['edit', $record->getKey()])->assertOk();
        $this->assertSame(['edit'], $this->livewireState($mounted)['mountedTableActions'][0]);
        $editSnapshot = $mounted->json('components.0.snapshot');
        $field = $resource === 'calendar_rules' ? 'day_of_week' : 'name';
        $newValue = $resource === 'calendar_rules' ? 3 : 'Updated through live action';
        $updates = ['mountedTableActionsData.0.'.$field => $newValue];
        $before = $record->getAttribute($field);

        // A linked Staff actor can still see this staff row, but cannot submit an edit.
        $this->saveMembership($first, $user, BusinessRole::Staff, BranchAccessScope::Selected);
        $this->livewire($editSnapshot, 'callMountedTableAction', updates: $updates)->assertOk();
        $this->assertSame($before, $record->fresh()->getAttribute($field));

        $this->saveMembership($first, $user, BusinessRole::Manager, BranchAccessScope::Selected, ['south']);
        $this->livewire($editSnapshot, 'callMountedTableAction', updates: $updates)->assertOk();
        $this->assertSame($before, $record->fresh()->getAttribute($field));

        app(ManageBusinessMemberships::class)->remove($first['business'], $user);
        $this->livewire($editSnapshot, 'callMountedTableAction', updates: $updates)->assertNotFound();
        $this->assertSame($before, $record->fresh()->getAttribute($field));

        $this->saveMembership($first, $user, BusinessRole::Manager, BranchAccessScope::Selected);
        $saved = $this->livewire($editSnapshot, 'callMountedTableAction', updates: $updates)->assertOk();
        $this->assertSame([], $this->livewireState($saved)['mountedTableActions'][0]);
        $this->assertEquals($newValue, $record->fresh()->getAttribute($field));

        if ($resource === 'services') {
            $shared = $first['records']['services']['shared']->fresh()->locations()->get()->keyBy('slug');
            $this->assertSame(BookingMode::StaffRequired, $shared['north']->pivot->booking_mode);
            $this->assertSame(BookingMode::Staffless, $shared['south']->pivot->booking_mode);
            $this->assertSame(3, $shared['south']->pivot->capacity);
        }

        $deletableKey = match ($resource) {
            'calendar_rules' => 'north',
            'services' => 'unused',
            'staff' => 'unlinked',
            'customers' => null,
        };
        $deleteTarget = $first['records'][$resource][$deletableKey ?? 'north'];
        $page = $this->snapshot($this->get($url)->assertOk(), $resource);
        $delete = $this->livewire($page, 'mountTableAction', ['delete', $deleteTarget->getKey()])->assertOk();
        if ($deletableKey === null) {
            $this->assertSame([], $this->livewireState($delete)['mountedTableActions'][0]);
            $this->assertNotNull($deleteTarget->fresh());

            return;
        }

        $this->assertSame(['delete'], $this->livewireState($delete)['mountedTableActions'][0]);
        $deleteSnapshot = $delete->json('components.0.snapshot');
        $this->saveMembership($first, $user, BusinessRole::Manager, BranchAccessScope::Selected, ['south']);
        $this->livewire($deleteSnapshot, 'callMountedTableAction')->assertOk();
        $this->assertNotNull($deleteTarget->fresh());
        $this->saveMembership($first, $user, BusinessRole::Manager, BranchAccessScope::Selected);
        $this->livewire($deleteSnapshot, 'callMountedTableAction')->assertOk();
        $this->assertNull($deleteTarget->fresh());
    }

    #[DataProvider('resources')]
    public function test_livewire_create_action_rechecks_the_role_before_creating_a_record(string $resource): void
    {
        $this->feature($resource, true);
        $fixture = $this->fixture('fixture');
        $actor = User::factory()->create();
        $this->saveMembership($fixture, $actor, BusinessRole::Manager, BranchAccessScope::Selected);
        $this->actingAs($actor, 'web');
        $page = $this->get($this->pageUrl($resource, $fixture['business']))->assertOk();
        $mounted = $this->livewire($this->snapshot($page, $resource), 'mountAction', ['create'])->assertOk();
        if ($resource === 'customers') {
            $this->assertSame([], $this->livewireState($mounted)['mountedActions'][0]);

            return;
        }

        $this->assertSame(['create'], $this->livewireState($mounted)['mountedActions'][0]);
        $snapshot = $mounted->json('components.0.snapshot');
        $name = 'Created through live action';
        $northLocationId = $fixture['locations']['north']->getKey();
        $data = match ($resource) {
            'calendar_rules' => [
                'calendar_id' => $fixture['records'][$resource]['north']->calendar_id,
                'day_of_week' => 2,
                'start_time' => '10:00',
                'end_time' => '11:00',
            ],
            'services' => [
                'name' => $name,
                'slug' => 'created-through-live-action',
                'duration_minutes' => 30,
                'slot_interval_minutes' => 30,
                'location_settings' => [[
                    'location_id' => $northLocationId,
                    'booking_mode' => BookingMode::StaffRequired->value,
                    'capacity' => 1,
                ]],
            ],
            'staff' => ['name' => $name, 'status' => 'active', 'locations' => [$northLocationId]],
        };
        $updates = [];
        foreach ($data as $key => $value) {
            $updates['mountedActionsData.0.'.$key] = $value;
        }
        $lookup = $resource === 'calendar_rules'
            ? ['calendar_id' => $data['calendar_id'], 'day_of_week' => $data['day_of_week']]
            : ['business_id' => $fixture['business']->getKey(), 'name' => $name];
        $table = $fixture['records'][$resource]['north']->getTable();

        $this->saveMembership($fixture, $actor, BusinessRole::Receptionist, BranchAccessScope::Selected);
        $this->livewire($snapshot, 'callMountedAction', updates: $updates)->assertOk();
        $this->assertDatabaseMissing($table, $lookup);
        $this->saveMembership($fixture, $actor, BusinessRole::Manager, BranchAccessScope::Selected);
        $saved = $this->livewire($snapshot, 'callMountedAction', updates: $updates)->assertOk();
        $this->assertSame([], $this->livewireState($saved)['mountedActions'][0]);
        $this->assertDatabaseHas($table, $lookup);
    }

    #[DataProvider('resources')]
    public function test_livewire_displays_search_or_filter_results_with_the_feature_enabled(string $resource): void
    {
        $this->feature($resource, true);
        $fixture = $this->fixture('fixture');
        $actor = User::factory()->create();
        $this->saveMembership($fixture, $actor, BusinessRole::Owner, BranchAccessScope::All);
        $this->actingAs($actor, 'web');
        $page = $this->get($this->pageUrl($resource, $fixture['business']))->assertOk();
        $snapshot = $this->snapshot($page, $resource);
        $updates = $resource === 'calendar_rules'
            ? ['tableFilters.calendar_id.value' => $fixture['records'][$resource]['north']->calendar_id]
            : ['tableSearch' => $fixture['records'][$resource]['north']->name];
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $filtered = $this->livewire($snapshot, updates: $updates)->assertOk();
            $membershipQueries = count(array_filter(
                DB::getQueryLog(),
                static fn (array $entry): bool => preg_match('/\bfrom\s+\x60?business_user\x60?(?=\s|$)/i', $entry['query']) === 1,
            ));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(1, $membershipQueries, "$resource Livewire request should load membership once");
        $html = $filtered->json('components.0.effects.html');
        $this->assertIsString($html);
        $this->assertSame(
            [$fixture['records'][$resource]['north']->getKey()],
            array_keys($this->renderedRows($html)),
        );
    }

    private function expectedRows(string $resource, string $actor): ?array
    {
        $edit = ['edit'];
        $manage = ['delete', 'edit'];
        $fullOperations = in_array($actor, ['owner', 'manager all'], true);
        $fullReception = $actor === 'receptionist all';
        $selectedOperations = $actor === 'manager selected';
        $selectedReception = $actor === 'receptionist selected';

        return match ($resource) {
            'calendar_rules' => match (true) {
                $fullOperations => ['north' => $manage, 'south' => $manage, 'shared_locations' => $manage, 'shared_staff' => $manage],
                $selectedOperations => ['north' => $manage, 'shared_locations' => [], 'shared_staff' => []],
                default => null,
            },
            'customers' => match (true) {
                $fullOperations || $fullReception => ['north' => $edit, 'south' => $edit, 'shared' => $edit],
                $selectedOperations || $selectedReception => ['north' => $edit, 'shared' => $edit],
                default => null,
            },
            'services' => match (true) {
                $fullOperations => ['north' => $edit, 'south' => $edit, 'shared' => $manage, 'unused' => $manage],
                $selectedOperations => ['north' => $edit, 'unused' => $manage],
                $fullReception => ['north' => [], 'south' => [], 'shared' => [], 'unused' => []],
                $selectedReception => ['north' => [], 'unused' => []],
                default => null,
            },
            'staff' => match (true) {
                $fullOperations => ['north' => $edit, 'south' => $edit, 'shared' => $manage, 'unlinked' => $manage],
                $selectedOperations => ['north' => $edit, 'unlinked' => $manage],
                $actor === 'linked staff' => ['north' => []],
                default => null,
            },
        };
    }

    private function assertRecordPolicies(string $resource, string $actor, User $user, array $records, array $rows): void
    {
        $gate = Gate::forUser($user);
        foreach ($records as $name => $record) {
            $actions = $rows[$name] ?? [];
            $view = $resource === 'calendar_rules' ? in_array('edit', $actions, true) : array_key_exists($name, $rows);
            $update = in_array('edit', $actions, true);
            $delete = in_array('delete', $actions, true);

            if ($resource === 'services' && $name === 'shared' && in_array($actor, ['manager selected', 'receptionist selected'], true)) {
                $view = true;
            }
            if ($resource === 'customers' && $name === 'unbooked' && $actor === 'owner') {
                $view = $update = $delete = true;
            }

            $this->assertSame($view, $gate->allows('view', $record), "$resource: $actor view $name");
            $this->assertSame($update, $gate->allows('update', $record), "$resource: $actor update $name");
            $this->assertSame($delete, $gate->allows('delete', $record), "$resource: $actor delete $name");
        }
    }

    private function saveMembership(array $fixture, User $user, BusinessRole $role, BranchAccessScope $scope, array $locations = ['north', 'shared_north', 'north_outreach']): void
    {
        app(ManageBusinessMemberships::class)->save(
            $fixture['business'],
            $user,
            $role,
            $scope,
            array_map(fn (string $key): string => $fixture['locations'][$key]->getKey(), $locations),
        );
    }

    private function feature(string $resource, bool $enabled): void
    {
        $disabledResources = array_fill_keys(array_keys(self::PAGES), true);
        $disabledResources[$resource] = false;

        config([
            'booking.resource_authorization_display_context.enabled' => $enabled,
            'booking.resource_authorization_display_context.disabled_resources' => $disabledResources,
        ]);
    }

    private function pageUrl(string $resource, Business $tenant): string
    {
        return route('filament.admin.resources.'.self::PAGES[$resource][0].'.index', ['tenant' => $tenant->getRouteKey()]);
    }

    private function assertRenderedRows(TestResponse $response, array $records, array $expected, bool $canCreate): void
    {
        $byId = [];
        foreach ($expected as $key => $actions) {
            $byId[$records[$key]->getKey()] = $actions;
        }
        ksort($byId);

        $html = $response->getContent();
        $this->assertSame($byId, $this->renderedRows($html));
        $this->assertSame($canCreate, str_contains(html_entity_decode($html, ENT_QUOTES, 'UTF-8'), "wire:click=\"mountAction('create')\""));
    }

    private function renderedRows(string $html): array
    {
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        preg_match_all('/\bwire:key="[^"]*\.table\.records\.([a-z0-9]+)"/i', $html, $keys);
        $rows = array_fill_keys($keys[1], []);
        preg_match_all('/\bwire:click="mountTableAction\(\'(edit|delete)\',\s*\'([a-z0-9]+)\'\)"/i', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $action, $id]) {
            $rows[$id][] = $action;
        }
        foreach ($rows as &$actions) {
            $actions = array_values(array_unique($actions));
            sort($actions);
        }
        unset($actions);
        ksort($rows);

        return $rows;
    }

    private function snapshot(TestResponse $response, string $resource): string
    {
        preg_match_all('/\bwire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES, 'UTF-8');
            $memo = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo'];
            if (str_ends_with($memo['name'], self::PAGES[$resource][1])) {
                return $snapshot;
            }
        }

        $this->fail("The $resource page did not render its Livewire snapshot.");
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

    private function fixture(string $slug): array
    {
        $business = Business::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'timezone' => 'UTC']);
        $calendars = [];
        $calendarRules = [];
        foreach (['north', 'south', 'shared_locations', 'shared_staff'] as $name) {
            $calendars[$name] = $business->availabilityCalendars()->create(['name' => "$slug calendar $name", 'timezone' => 'UTC']);
            $calendarRules[$name] = $calendars[$name]->rules()->create(['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:00']);
        }

        $locations = [];
        foreach (['north' => 'north', 'south' => 'south', 'shared_north' => 'shared_locations', 'shared_south' => 'shared_locations', 'north_outreach' => 'shared_staff'] as $branch => $calendar) {
            $locations[$branch] = $business->locations()->create([
                'name' => "$slug location $branch", 'slug' => $branch, 'calendar_id' => $calendars[$calendar]->getKey(), 'timezone' => 'UTC',
            ]);
        }

        $services = [];
        foreach (['north' => ['north'], 'south' => ['south'], 'shared' => ['north', 'south'], 'unused' => ['north']] as $name => $branches) {
            $service = $business->services()->create([
                'name' => "$slug service $name", 'slug' => $name, 'duration_minutes' => 30, 'slot_interval_minutes' => 30,
            ]);
            foreach ($branches as $branch) {
                $staffless = $name === 'shared' && $branch === 'south';
                $service->locations()->attach($locations[$branch], [
                    'booking_mode' => $staffless ? BookingMode::Staffless : BookingMode::StaffRequired,
                    'capacity' => $staffless ? 3 : 1,
                ]);
            }
            $services[$name] = $service;
        }

        $staff = [];
        foreach (['north' => ['north'], 'south' => ['south'], 'shared' => ['north', 'south'], 'unlinked' => ['north']] as $name => $branches) {
            $employee = $business->staff()->create([
                'name' => "$slug staff $name",
                'calendar_id' => $name === 'shared' ? $calendars['shared_staff']->getKey() : null,
                'status' => 'active',
            ]);
            foreach ($branches as $branch) {
                $employee->locations()->attach($locations[$branch], ['is_active' => true]);
            }
            $staff[$name] = $employee;
        }

        $customers = [];
        foreach (['north' => ['north'], 'south' => ['south'], 'shared' => ['north', 'south'], 'unbooked' => []] as $name => $branches) {
            $customer = $business->customers()->create(['name' => "$slug customer $name"]);
            foreach ($branches as $branch) {
                $reference = "$slug-$name-$branch";
                $this->booking($business, $locations[$branch], $services[$branch], $staff[$branch], $customer, $reference);
            }
            $customers[$name] = $customer;
        }

        return [
            'business' => $business,
            'locations' => $locations,
            'records' => ['calendar_rules' => $calendarRules, 'customers' => $customers, 'services' => $services, 'staff' => $staff],
        ];
    }

    private function booking(Business $business, Location $branch, Model $service, Model $staff, Model $customer, string $reference): void
    {
        $business->bookings()->create([
            'location_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => $reference,
            'status' => BookingStatus::Confirmed,
            'starts_at' => '2026-01-05 08:00:00',
            'ends_at' => '2026-01-05 08:30:00',
            'occupied_starts_at' => '2026-01-05 08:00:00',
            'occupied_ends_at' => '2026-01-05 08:30:00',
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => 30,
        ]);
    }
}
