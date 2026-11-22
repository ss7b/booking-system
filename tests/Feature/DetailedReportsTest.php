<?php

namespace Tests\Feature;

use App\Domain\Authorization\ManageBusinessMemberships;
use App\Domain\Reports\BookingReports;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportRange;
use App\Enums\BookingStatus;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DetailedReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_reports_in_arabic_and_english_with_an_empty_branch(): void
    {
        $business = $this->business('reports-empty');
        $branch = $this->branch($business, 'Main branch');
        $user = $this->member($business, BusinessRole::Owner);
        $this->actingAs($user, 'web');

        $arabic = $this->get($this->url($business))
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('التقارير')
            ->assertDontSee('href="'.route('filament.admin.pages.reports', ['tenant' => $business]).'"', false);
        $state = $this->state($this->snapshot($arabic));
        $this->assertSame('reports', $state['activeTab']);
        $this->assertSame($branch->getKey(), $state['branchId']);
        $this->assertSame(ReportPeriod::CurrentMonth->value, $state['period']);

        $this->from($this->url($business))->post('/admin/locale', ['locale' => 'en'])
            ->assertRedirect($this->url($business));
        $this->get($this->url($business))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Reports')
            ->assertSee('Today')
            ->assertSee('This week')
            ->assertSee('This month')
            ->assertSee('No bookings match this report.');
    }

    public function test_dashboard_tabs_share_filters_and_keep_reports_lazy_until_selected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $business = $this->business('unified-dashboard');
        $branch = $this->branch($business, 'Main branch');
        $owner = $this->member($business, BusinessRole::Owner);
        $this->booking($branch, 'unified-booking', '2026-11-05 09:00:00');
        $this->actingAs($owner, 'web');

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $dashboard = $this->get(route('filament.admin.pages.dashboard', ['tenant' => $business]))
                ->assertOk()
                ->assertSee('لوحة التحكم')
                ->assertSee('التقارير')
                ->assertDontSee('data-reports-page', false);
            $dashboardBookingQueries = array_filter(
                DB::getQueryLog(),
                fn (array $query): bool => preg_match('/\bfrom\s+`?bookings`?\b/i', $query['query']) === 1,
            );
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertNotEmpty(array_filter(
            $dashboardBookingQueries,
            fn (array $query): bool => preg_match('/left\s+join\s+`?services`?\b/i', $query['query']) === 1,
        ));

        $snapshot = $this->snapshot($dashboard);
        $this->assertSame('dashboard', $this->state($snapshot)['activeTab']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $reports = $this->update($snapshot, [], 'selectTab', ['reports'])->assertOk();
            $reportsBookingQueries = array_filter(
                DB::getQueryLog(),
                fn (array $query): bool => preg_match('/\bfrom\s+`?bookings`?\b/i', $query['query']) === 1,
            );
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertEmpty(array_filter(
            $reportsBookingQueries,
            fn (array $query): bool => preg_match('/left\s+join\s+`?services`?\b/i', $query['query']) === 1,
        ));
        $reportsSnapshot = $this->nextSnapshot($reports);
        $this->assertSame('reports', $this->state($reportsSnapshot)['activeTab']);
        $this->assertStringContainsString('data-reports-page', $this->html($reports));

        $filtered = $this->apply($reportsSnapshot, $branch, 'custom', '2026-11-05', '2026-11-05')->assertOk();
        $filteredSnapshot = $this->nextSnapshot($filtered);
        $this->assertSame($branch->getKey(), $this->state($filteredSnapshot)['branchId']);
        $this->assertSame('custom', $this->state($filteredSnapshot)['period']);
        $this->assertSame('2026-11-05', $this->state($filteredSnapshot)['startDate']);

        $dashboardAgain = $this->update($filteredSnapshot, [], 'selectTab', ['dashboard'])->assertOk();
        $dashboardSnapshot = $this->nextSnapshot($dashboardAgain);
        $this->assertSame($branch->getKey(), $this->state($dashboardSnapshot)['branchId']);
        $this->assertSame('custom', $this->state($dashboardSnapshot)['period']);
        $this->assertStringContainsString('data-dashboard-mode="analytics"', $this->html($dashboardAgain));
        $this->assertStringNotContainsString('data-reports-page', $this->html($dashboardAgain));
        $this->assertSame('1', $this->metric($this->html($dashboardAgain), 'total'));
    }

    public function test_legacy_reports_route_redirects_to_the_authorized_reports_tab(): void
    {
        $business = $this->business('legacy-reports-link');
        $this->branch($business, 'Main branch');
        $owner = $this->member($business, BusinessRole::Owner);
        $this->actingAs($owner, 'web');

        $this->get(route('filament.admin.pages.reports', ['tenant' => $business]))
            ->assertRedirect(route('filament.admin.pages.dashboard', ['tenant' => $business, 'tab' => 'reports']));
    }

    public function test_unauthorized_users_cannot_open_the_reports_tab_by_url_or_old_route(): void
    {
        $business = $this->business('forbidden-reports-tab');
        $branch = $this->branch($business, 'Main branch');
        $operator = $this->member($business, BusinessRole::Receptionist, [$branch]);
        $this->actingAs($operator, 'web');

        $this->get(route('filament.admin.pages.dashboard', ['tenant' => $business, 'tab' => 'reports']))
            ->assertForbidden();
        $this->get(route('filament.admin.pages.reports', ['tenant' => $business]))
            ->assertForbidden();

        $dashboard = $this->get(route('filament.admin.pages.dashboard', ['tenant' => $business]))->assertOk();
        $this->update($this->snapshot($dashboard), ['activeTab' => 'reports'])->assertForbidden();
    }

    public function test_invalid_tab_query_defaults_to_dashboard(): void
    {
        $business = $this->business('invalid-dashboard-tab');
        $this->branch($business, 'Main branch');
        $owner = $this->member($business, BusinessRole::Owner);
        $this->actingAs($owner, 'web');

        $page = $this->get(route('filament.admin.pages.dashboard', ['tenant' => $business, 'tab' => 'unexpected']))
            ->assertOk()
            ->assertSee('data-dashboard-mode="analytics"', false)
            ->assertDontSee('data-reports-page', false);

        $this->assertSame('dashboard', $this->state($this->snapshot($page))['activeTab']);
    }

    #[DataProvider('operationalRoles')]
    public function test_operational_role_cannot_open_reports_or_reuse_an_analytics_snapshot(BusinessRole $role): void
    {
        $business = $this->business('reports-denied');
        $branch = $this->branch($business, 'Main branch');
        $owner = $this->member($business, BusinessRole::Owner);
        $operator = $this->member($business, $role, [$branch]);
        $ownerReport = $this->actingAs($owner, 'web')->get($this->url($business))->assertOk();
        $snapshot = $this->snapshot($ownerReport);

        $this->actingAs($operator, 'web');
        $this->get(route('filament.admin.pages.dashboard', ['tenant' => $business]))
            ->assertOk()
            ->assertDontSee($this->url($business), false);
        $this->get($this->url($business))->assertForbidden();
        $this->apply($snapshot, $branch)->assertForbidden();
        $this->update($snapshot, ['bookingSearch' => 'anything'], 'searchBookings')->assertForbidden();
        $this->assertThrows(
            fn () => app(BookingReports::class)->bookings(
                $operator, $business, $branch->getKey(), ReportRange::preset(ReportPeriod::Today),
            ),
            AuthorizationException::class,
        );
    }

    public static function operationalRoles(): array
    {
        return [
            'receptionist' => [BusinessRole::Receptionist],
            'staff' => [BusinessRole::Staff],
        ];
    }

    public function test_manager_filters_and_revoked_branches_are_validated_on_livewire_requests(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $business = $this->business('report-branches');
        $north = $this->branch($business, 'Allowed north');
        $south = $this->branch($business, 'Allowed south');
        $west = $this->branch($business, 'Excluded west');
        $user = $this->member($business, BusinessRole::Manager, [$north, $south]);
        $northBooking = $this->booking($north, 'north-only', '2026-11-05 10:00:00');
        $southBooking = $this->booking($south, 'south-only', '2026-11-05 11:00:00');
        $westBooking = $this->booking($west, 'west-only', '2026-11-05 12:00:00');
        $this->actingAs($user, 'web');

        $page = $this->get($this->url($business))->assertOk()
            ->assertSee('Allowed north')
            ->assertSee('Allowed south')
            ->assertDontSee('Excluded west')
            ->assertDontSee($northBooking->reference);
        $snapshot = $this->snapshot($page);
        $this->assertNull($this->state($snapshot)['branchId']);

        $northResult = $this->apply($snapshot, $north)->assertOk();
        $northSnapshot = $this->nextSnapshot($northResult);
        $this->assertSame($north->getKey(), $this->state($northSnapshot)['branchId']);
        $this->assertStringContainsString($northBooking->reference, $this->html($northResult));
        $this->assertStringNotContainsString($southBooking->reference, $this->html($northResult));

        $invalid = $this->apply($northSnapshot, $west)->assertOk();
        $invalidSnapshot = $this->nextSnapshot($invalid);
        $this->assertSame($north->getKey(), $this->state($invalidSnapshot)['branchId']);
        $this->assertStringNotContainsString($westBooking->reference, $this->html($invalid));

        $this->member($business, BusinessRole::Manager, [$south, $west], $user);
        $revoked = $this->update($invalidSnapshot, [])->assertOk();
        $revokedSnapshot = $this->nextSnapshot($revoked);
        $this->assertNull($this->state($revokedSnapshot)['branchId']);
        $this->assertStringNotContainsString($northBooking->reference, $this->html($revoked));
        $this->assertStringNotContainsString('Allowed north', $this->html($revoked));

        $return = $this->apply($revokedSnapshot, $south)->assertOk();
        $this->assertSame($south->getKey(), $this->state($this->nextSnapshot($return))['branchId']);
        $this->assertStringContainsString($southBooking->reference, $this->html($return));
    }

    public function test_reports_tenant_switch_uses_new_branch_and_rejects_a_foreign_branch(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $first = $this->business('reports-first');
        $second = $this->business('reports-second');
        $firstBranch = $this->branch($first, 'First branch');
        $secondBranch = $this->branch($second, 'Second branch');
        $firstBooking = $this->booking($firstBranch, 'first-only', '2026-11-05 10:00:00');
        $secondBooking = $this->booking($secondBranch, 'second-only', '2026-11-05 11:00:00');
        $user = $this->member($first, BusinessRole::Owner);
        $this->actingAs($user, 'web');

        $firstPage = $this->get($this->url($first))->assertOk()
            ->assertSee($firstBooking->reference)->assertDontSee($secondBooking->reference);
        $firstApplied = $this->apply($this->snapshot($firstPage), $firstBranch, ReportPeriod::Today)->assertOk();
        $this->assertSame(ReportPeriod::Today->value, $this->state($this->nextSnapshot($firstApplied))['period']);
        $this->get($this->url($second))->assertNotFound();

        $this->member($second, BusinessRole::Owner, user: $user);
        $secondPage = $this->get($this->url($second))->assertOk()
            ->assertSee($secondBooking->reference)->assertDontSee($firstBooking->reference);
        $secondSnapshot = $this->snapshot($secondPage);
        $state = $this->state($secondSnapshot);
        $this->assertSame($secondBranch->getKey(), $state['branchId']);
        $this->assertSame(ReportPeriod::CurrentMonth->value, $state['period']);

        $attempt = $this->apply($secondSnapshot, $firstBranch)->assertOk();
        $this->assertSame($secondBranch->getKey(), $this->state($this->nextSnapshot($attempt))['branchId']);
        $this->assertStringNotContainsString($firstBooking->reference, $this->html($attempt));
    }

    public function test_reports_reuse_dashboard_metrics_and_include_all_services_and_staffless(): void
    {
        $now = CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC');
        $this->travelTo($now);
        $business = $this->business('report-parity');
        $branch = $this->branch($business, 'Main branch');
        $owner = $this->member($business, BusinessRole::Owner);
        $owner->forceFill(['preferred_locale' => 'en'])->save();
        $staff = $business->staff()->create(['name' => 'Roster employee', 'status' => 'active']);
        $primary = $this->service($business, 'Primary service');
        $unused = $this->service($business, 'Never confirmed');
        $confirmed = $this->booking($branch, 'cohort-confirmed', '2026-11-05 09:00:00', BookingStatus::Confirmed, $primary, $staff);
        $checkedIn = $this->booking($branch, 'cohort-checked-in', '2026-11-05 09:30:00', BookingStatus::CheckedIn, $primary, $staff);
        $completed = $this->booking($branch, 'cohort-completed', '2026-11-05 10:00:00', BookingStatus::Completed, $primary);
        $cancelled = $this->booking($branch, 'cohort-cancelled', '2026-11-05 10:30:00', BookingStatus::Cancelled, $primary);
        $noShow = $this->booking($branch, 'cohort-no-show', '2026-11-05 11:00:00', BookingStatus::NoShow, $primary, $staff);
        $held = $this->booking($branch, 'cohort-held', '2026-11-05 11:30:00', BookingStatus::Held, $primary, attributes: [
            'hold_expires_at' => $now->addMinute(),
        ]);
        $this->booking($branch, 'cohort-stale-hold', '2026-11-05 12:00:00', BookingStatus::Held, $unused, attributes: [
            'hold_expires_at' => $now,
        ]);
        $this->booking($branch, 'cohort-abandoned', '2026-11-05 12:30:00', BookingStatus::Cancelled, $unused, attributes: [
            'confirmed_at' => null,
        ]);
        $this->booking($branch, 'cohort-expired', '2026-11-05 13:00:00', BookingStatus::Expired, $unused);
        foreach (range(1, 5) as $index) {
            $other = $this->service($business, 'Secondary '.$index);
            $this->booking($branch, 'cohort-secondary-'.$index, '2026-11-05 14:00:00', service: $other);
        }

        $reports = app(BookingReports::class);
        $range = ReportRange::preset(ReportPeriod::CurrentMonth);
        $dashboard = $reports->forPeriod($owner, $business, $branch->getKey(), ReportPeriod::CurrentMonth);
        $overview = $reports->overview($owner, $business, $branch->getKey(), $range);
        $this->assertSame($dashboard['statistics'], $overview['statistics']);
        $this->assertSame($dashboard['trend'], $overview['trend']);
        $this->assertEquals([
            'total' => 10, 'confirmed' => 6, 'completed' => 1, 'cancelled' => 1, 'pending' => 1, 'cancellation_rate' => 10,
        ], $overview['statistics']);
        $this->assertSame(10, $overview['trend']['2026-11-05']);
        $this->assertSame(1, $overview['statuses'][BookingStatus::Held->value]);
        $this->assertArrayNotHasKey(BookingStatus::Expired->value, $overview['statuses']);

        $services = $reports->services($owner, $business, $branch->getKey(), $range);
        $this->assertCount(5, $dashboard['services']);
        $this->assertSame(6, $services->total());
        $primaryRow = collect($services->items())->firstWhere('id', $primary->getKey());
        $this->assertSame(5, $primaryRow['total']);
        $this->assertSame(1, $primaryRow['confirmed']);
        $this->assertSame(1, $primaryRow['checked_in']);
        $this->assertSame(1, $primaryRow['completed']);
        $this->assertSame(1, $primaryRow['cancelled']);
        $this->assertSame(1, $primaryRow['no_show']);
        $this->assertSame(1, $primaryRow['pending']);
        $this->assertEquals(20, $primaryRow['cancellation_rate']);
        $this->assertNotContains($unused->getKey(), array_column($services->items(), 'id'));
        $sortedServicePage = $reports->services(
            $owner, $business, $branch->getKey(), $range, sort: 'name', direction: 'asc', perPage: 2, page: 2,
        );
        $this->assertSame(['Secondary 2', 'Secondary 3'], array_column($sortedServicePage->items(), 'name'));
        $this->assertSame(6, $sortedServicePage->total());

        $staffRows = $reports->staff($owner, $business, $branch->getKey(), $range);
        $this->assertSame(2, $staffRows->total());
        $this->assertSame([7, 3], array_column($staffRows->items(), 'total'));
        $staffless = collect($staffRows->items())->firstWhere('id', null);
        $this->assertNull($staffless['name']);
        $this->assertSame(7, $staffless['total']);
        $this->assertSame(1, $staffless['pending']);
        $this->assertSame(1, $staffless['cancelled']);

        $details = $reports->bookings($owner, $business, $branch->getKey(), $range);
        $this->assertSame(11, $details->total());
        foreach ([$confirmed, $checkedIn, $completed, $cancelled, $noShow, $held] as $booking) {
            $this->assertContains($booking->reference, array_column($details->items(), 'reference'));
        }
        $onlyHeld = $reports->bookings($owner, $business, $branch->getKey(), $range, status: BookingStatus::Held);
        $this->assertSame([$held->reference], array_column($onlyHeld->items(), 'reference'));

        $this->actingAs($owner, 'web');
        $page = $this->get($this->url($business))->assertOk()->assertSee($held->reference)->assertDontSee('cohort-stale-hold');
        $serviceSection = $this->update($this->snapshot($page), [], 'showSection', ['services'])->assertOk();
        $this->assertStringContainsString('data-report-section="services"', $this->html($serviceSection));
        $this->assertStringContainsString('Secondary 5', $this->html($serviceSection));
        $staffSection = $this->update($this->nextSnapshot($serviceSection), [], 'showSection', ['staff'])->assertOk();
        $this->assertStringContainsString('data-report-section="staff"', $this->html($staffSection));
        $this->assertStringContainsString('Staffless', $this->html($staffSection));
        $this->assertStringContainsString('Roster employee', $this->html($staffSection));
    }

    public function test_custom_local_dates_are_inclusive_across_dst_and_rejected_when_invalid(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-09 12:00:00', 'UTC'));
        $business = $this->business('report-custom');
        $branch = $this->branch($business, 'Pacific branch', 'America/Los_Angeles');
        $owner = $this->member($business, BusinessRole::Owner);
        $owner->forceFill(['preferred_locale' => 'en'])->save();
        $this->booking($branch, 'outside-start', '2026-03-07 07:59:59');
        $first = $this->booking($branch, 'included-first', '2026-03-07 08:00:00');
        $this->booking($branch, 'before-clock-change', '2026-03-08 07:59:59');
        $this->booking($branch, 'day-of-clock-change', '2026-03-08 08:00:00');
        $last = $this->booking($branch, 'included-last', '2026-03-09 06:59:59');
        $this->booking($branch, 'outside-end', '2026-03-09 07:00:00');

        $range = ReportRange::custom('2026-03-07', '2026-03-08');
        $overview = app(BookingReports::class)->overview($owner, $business, $branch->getKey(), $range);
        $this->assertSame('2026-03-07T00:00:00-08:00', $overview['start']->toIso8601String());
        $this->assertSame('2026-03-09T00:00:00-07:00', $overview['end']->toIso8601String());
        $this->assertSame(['2026-03-07' => 2, '2026-03-08' => 2], $overview['trend']);

        $this->actingAs($owner, 'web');
        $initial = $this->snapshot($this->get($this->url($business))->assertOk());
        $draft = $this->update($initial, ['filterData.period' => 'custom'])->assertOk();
        $this->assertSame(ReportPeriod::CurrentMonth->value, $this->state($this->nextSnapshot($draft))['period']);

        $custom = $this->apply($this->nextSnapshot($draft), $branch, 'custom', '2026-03-07', '2026-03-08')->assertOk();
        $snapshot = $this->nextSnapshot($custom);
        $this->assertSame('custom', $this->state($snapshot)['period']);
        $this->assertSame('2026-03-07', $this->state($snapshot)['startDate']);
        $this->assertSame('2026-03-08', $this->state($snapshot)['endDate']);
        $this->assertStringContainsString($first->reference, $this->html($custom));
        $this->assertStringContainsString($last->reference, $this->html($custom));
        $this->assertStringNotContainsString('outside-start', $this->html($custom));
        $this->assertStringNotContainsString('outside-end', $this->html($custom));

        $reversed = $this->apply($snapshot, $branch, 'custom', '2026-03-08', '2026-03-07')->assertOk();
        $this->assertSame('2026-03-07', $this->state($this->nextSnapshot($reversed))['startDate']);
        $this->assertStringContainsString('Enter an end date on or after the start date.', $this->html($reversed));
        $invalidDate = $this->apply($this->nextSnapshot($reversed), $branch, 'custom', '2026-02-30', '2026-03-08')->assertOk();
        $this->assertSame('2026-03-07', $this->state($this->nextSnapshot($invalidDate))['startDate']);
        $this->assertStringContainsString('Enter a valid start date.', $this->html($invalidDate));
    }

    public function test_preset_filters_use_the_selected_branch_date_and_reset_custom_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-07 21:30:00', 'UTC'));
        $business = $this->business('report-presets');
        $branch = $this->branch($business, 'Riyadh branch');
        $owner = $this->member($business, BusinessRole::Owner);
        $this->booking($branch, 'preset-previous-week', '2026-11-07 20:59:59');
        $this->booking($branch, 'preset-today', '2026-11-07 21:00:00');
        $this->booking($branch, 'preset-this-week', '2026-11-08 21:00:00');
        $this->booking($branch, 'preset-next-week', '2026-11-14 21:00:00');
        $this->actingAs($owner, 'web');
        $snapshot = $this->snapshot($this->get($this->url($business))->assertOk());

        $custom = $this->apply($snapshot, $branch, 'custom', '2026-11-01', '2026-11-30')->assertOk();
        $today = $this->apply($this->nextSnapshot($custom), $branch, ReportPeriod::Today)->assertOk();
        $todayState = $this->state($this->nextSnapshot($today));
        $this->assertNull($todayState['startDate']);
        $this->assertNull($todayState['endDate']);
        $this->assertStringContainsString('preset-today', $this->html($today));
        $this->assertStringNotContainsString('preset-previous-week', $this->html($today));
        $this->assertStringNotContainsString('preset-this-week', $this->html($today));

        $week = $this->apply($this->nextSnapshot($today), $branch, ReportPeriod::CurrentWeek)->assertOk();
        $this->assertStringContainsString('preset-today', $this->html($week));
        $this->assertStringContainsString('preset-this-week', $this->html($week));
        $this->assertStringNotContainsString('preset-previous-week', $this->html($week));
        $this->assertStringNotContainsString('preset-next-week', $this->html($week));

        $month = $this->apply($this->nextSnapshot($week), $branch)->assertOk();
        $this->assertStringContainsString('preset-previous-week', $this->html($month));
        $this->assertStringContainsString('preset-next-week', $this->html($month));
    }

    public function test_booking_details_are_searchable_sortable_and_paginated_within_branch(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $business = $this->business('report-table');
        $branch = $this->branch($business, 'Visible branch');
        $restricted = $this->branch($business, 'Restricted branch');
        $user = $this->member($business, BusinessRole::Manager, [$branch]);
        $user->forceFill(['preferred_locale' => 'en'])->save();
        $this->booking($restricted, 'private-needle', '2026-11-29 09:00:00');
        $staff = $business->staff()->create(['name' => 'Searchable staff member', 'status' => 'active']);
        $customer = $business->customers()->create(['name' => 'Searchable customer']);
        foreach (range(1, 16) as $day) {
            $this->booking(
                $branch,
                sprintf('visible-%02d-match', $day),
                sprintf('2026-11-%02d 09:00:00', $day),
                staff: $day === 10 ? $staff : null,
                attributes: $day === 10 ? [
                    'customer_id' => $customer->getKey(), 'service_name_snapshot' => 'Searchable historic service',
                ] : [],
            );
        }
        $this->actingAs($user, 'web');
        $page = $this->get($this->url($business))->assertOk()
            ->assertSeeInOrder(['visible-16-match', 'visible-02-match'])
            ->assertDontSee('visible-01-match')
            ->assertDontSee('private-needle');

        $secondPage = $this->update($this->snapshot($page), [], 'setPage', [2, 'bookingsPage'])->assertOk();
        $this->assertStringContainsString('visible-01-match', $this->html($secondPage));
        $this->assertStringNotContainsString('visible-02-match', $this->html($secondPage));

        $ascending = $this->update($this->nextSnapshot($secondPage), [], 'sortBookings', ['reference'])->assertOk();
        $this->assertStringContainsString('visible-01-match', $this->html($ascending));
        $this->assertStringNotContainsString('visible-16-match', $this->html($ascending));
        $this->assertLessThan(strpos($this->html($ascending), 'visible-15-match'), strpos($this->html($ascending), 'visible-01-match'));

        $snapshot = $this->nextSnapshot($ascending);
        foreach (['visible-10', 'Searchable historic service', 'Searchable staff member', 'Searchable customer'] as $search) {
            $match = $this->update($snapshot, ['bookingSearch' => $search], 'searchBookings')->assertOk();
            $this->assertStringContainsString('visible-10-match', $this->html($match));
            $this->assertStringNotContainsString('visible-09-match', $this->html($match));
            $snapshot = $this->nextSnapshot($match);
        }
        $excluded = $this->update($snapshot, ['bookingSearch' => 'private-needle'], 'searchBookings')->assertOk();
        $this->assertStringContainsString('No bookings match this report.', $this->html($excluded));
    }

    private function business(string $slug): Business
    {
        return Business::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
            'is_active' => true,
        ]);
    }

    private function branch(Business $business, string $name, string $timezone = 'Asia/Riyadh'): Location
    {
        return $business->locations()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'timezone' => $timezone,
            'is_active' => true,
        ]);
    }

    /** @param list<Location> $branches */
    private function member(Business $business, BusinessRole $role, array $branches = [], ?User $user = null): User
    {
        $user ??= User::factory()->create();
        app(ManageBusinessMemberships::class)->save(
            $business,
            $user,
            $role,
            $branches === [] ? BranchAccessScope::All : BranchAccessScope::Selected,
            array_map(fn (Location $branch): string => $branch->getKey(), $branches),
        );

        return $user;
    }

    private function service(Business $business, string $name = 'Consultation'): Service
    {
        return $business->services()->firstOrCreate(['slug' => str($name)->slug()->toString()], [
            'name' => $name,
            'duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'is_active' => true,
        ]);
    }

    private function booking(
        Location $branch,
        string $reference,
        string $utcStart,
        BookingStatus $status = BookingStatus::Confirmed,
        ?Service $service = null,
        ?Staff $staff = null,
        array $attributes = [],
    ): Booking {
        $business = $branch->business;
        $service ??= $this->service($business);
        $customer = $business->customers()->firstOrCreate(['name' => 'Reports fixture customer']);
        $start = CarbonImmutable::parse($utcStart, 'UTC');
        $end = $start->addMinutes(30);

        return $business->bookings()->create([
            'location_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff?->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => $reference,
            'status' => $status,
            'source' => 'test',
            'starts_at' => $start,
            'ends_at' => $end,
            'occupied_starts_at' => $start,
            'occupied_ends_at' => $end,
            'confirmed_at' => in_array($status, [BookingStatus::Held, BookingStatus::Expired], true) ? null : $start->subDay(),
            'hold_expires_at' => $status === BookingStatus::Held ? $start->addMinutes(15) : null,
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => 30,
            ...$attributes,
        ]);
    }

    private function url(Business $business): string
    {
        return route('filament.admin.pages.dashboard', ['tenant' => $business, 'tab' => 'reports']);
    }

    private function snapshot(TestResponse $page): string
    {
        preg_match_all('/\bwire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES, 'UTF-8');
            if (str_ends_with(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['name'], 'pages.dashboard')) {
                return $snapshot;
            }
        }

        $this->fail('The Reports page did not render a signed Livewire snapshot.');
    }

    private function state(string $snapshot): array
    {
        return json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    private function nextSnapshot(TestResponse $result): string
    {
        return $result->json('components.0.snapshot');
    }

    private function html(TestResponse $result): string
    {
        return $result->json('components.0.effects.html');
    }

    private function metric(string $html, string $name): string
    {
        $page = new \DOMDocument;
        @$page->loadHTML($html);
        $xpath = new \DOMXPath($page);

        return trim($xpath->evaluate('string(//*[@data-dashboard-stat="'.$name.'"]//*[contains(@class, "fi-wi-stats-overview-stat-value")])'));
    }

    private function apply(string $snapshot, Location $branch, ReportPeriod|string $period = ReportPeriod::CurrentMonth, ?string $start = null, ?string $end = null): TestResponse
    {
        return $this->update($snapshot, [
            'filterData.branch_id' => $branch->getKey(),
            'filterData.period' => $period instanceof ReportPeriod ? $period->value : $period,
            'filterData.start_date' => $start,
            'filterData.end_date' => $end,
        ], 'applyFilters');
    }

    private function update(string $snapshot, array $updates, ?string $method = null, array $parameters = []): TestResponse
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => $method === null ? [] : [['path' => '', 'method' => $method, 'params' => $parameters]],
        ]]], ['X-Livewire' => 'true']);
    }
}
