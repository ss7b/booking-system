<?php

namespace Tests\Feature;

use App\Domain\Authorization\ManageBusinessMemberships;
use App\Domain\Reports\BookingReports;
use App\Domain\Reports\ReportPeriod;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_the_automatically_selected_branch_and_can_switch_dashboard_language(): void
    {
        $business = $this->business('owner');
        $branch = $this->branch($business, 'Main Branch');
        $user = $this->member($business, BusinessRole::Owner);
        $this->actingAs($user, 'web');

        $arabicPage = $this->get($this->dashboardUrl($business))
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('data-dashboard-mode="analytics"', false)
            ->assertSee('تحليلات الحجوزات');
        $snapshot = $this->dashboardSnapshot($arabicPage);
        $this->assertSame($branch->getKey(), $this->selectedBranch($snapshot));
        $this->assertSame(ReportPeriod::CurrentMonth->value, $this->selectedPeriod($snapshot));

        $this->from($this->dashboardUrl($business))->post('/admin/locale', ['locale' => 'en'])
            ->assertRedirect($this->dashboardUrl($business));
        $this->get($this->dashboardUrl($business))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Booking analytics')
            ->assertSee('Total bookings')
            ->assertSee('Today')
            ->assertSee('This week')
            ->assertSee('This month');
    }

    public function test_manager_livewire_filters_select_only_permitted_branches_and_refresh_results(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $business = $this->business('manager');
        $north = $this->branch($business, 'Filter North');
        $south = $this->branch($business, 'Filter South');
        $restricted = $this->branch($business, 'Restricted Branch');
        $user = $this->member($business, BusinessRole::Manager, [$north, $south]);
        $northService = $this->service($business, 'Northern service');
        $southService = $this->service($business, 'Southern service');
        $southStaff = $business->staff()->create(['name' => 'Southern team member', 'status' => 'active']);
        $northBooking = $this->booking($north, $northService, BookingStatus::Confirmed, '2026-11-06 08:00:00');
        $southBooking = $this->booking($south, $southService, BookingStatus::Confirmed, '2026-11-07 09:00:00', $southStaff);
        $this->booking($south, $southService, BookingStatus::Completed, '2026-11-04 09:00:00', $southStaff);
        $restrictedBooking = $this->booking($restricted, $northService, BookingStatus::Confirmed, '2026-11-06 10:00:00');
        $user->forceFill(['preferred_locale' => 'en'])->save();
        $this->actingAs($user, 'web');

        $initial = $this->get($this->dashboardUrl($business))->assertOk()
            ->assertSee('Filter North')
            ->assertSee('Filter South')
            ->assertDontSee('Restricted Branch')
            ->assertDontSee($northBooking->reference)
            ->assertDontSee($southBooking->reference)
            ->assertSee('wire:model="filterData.branch_id"', false)
            ->assertSee('wire:model="filterData.period"', false);
        $snapshot = $this->dashboardSnapshot($initial);
        $this->assertNull($this->selectedBranch($snapshot));

        $northResult = $this->applyFilters($snapshot, $north->getKey())->assertOk();
        $northSnapshot = $northResult->json('components.0.snapshot');
        $this->assertSame($north->getKey(), $this->selectedBranch($northSnapshot));
        $northHtml = $northResult->json('components.0.effects.html');
        $this->assertStringContainsString($northBooking->reference, $northHtml);
        $this->assertStringNotContainsString($southBooking->reference, $northHtml);
        $this->assertStringContainsString('Northern service', $northHtml);
        $this->assertStringContainsString('Staffless', $northHtml);
        $this->assertSame('1', $this->metric($northHtml, 'total'));

        $southResult = $this->applyFilters($northSnapshot, $south->getKey())->assertOk();
        $southSnapshot = $southResult->json('components.0.snapshot');
        $this->assertSame($south->getKey(), $this->selectedBranch($southSnapshot));
        $southHtml = $southResult->json('components.0.effects.html');
        $this->assertStringContainsString($southBooking->reference, $southHtml);
        $this->assertStringNotContainsString($northBooking->reference, $southHtml);
        $this->assertStringContainsString('Southern service', $southHtml);
        $this->assertStringNotContainsString('Northern service', $southHtml);
        $this->assertStringContainsString('Southern team member', $southHtml);
        $this->assertStringNotContainsString('Staffless', $southHtml);
        $this->assertSame('2', $this->metric($southHtml, 'total'));
        $this->assertMatchesRegularExpression('/wire:key="dashboard-trend-[a-f0-9]+"/', $northHtml);
        $this->assertMatchesRegularExpression('/wire:key="dashboard-trend-[a-f0-9]+"/', $southHtml);
        preg_match('/wire:key="dashboard-trend-[a-f0-9]+"/', $northHtml, $trendKey);
        $this->assertStringNotContainsString($trendKey[0], $southHtml);

        $invalid = $this->applyFilters($southSnapshot, $restricted->getKey())->assertOk();
        $this->assertSame($south->getKey(), $this->selectedBranch($invalid->json('components.0.snapshot')));
        $this->assertStringContainsString('Select a branch you have access to.', $invalid->json('components.0.effects.html'));
        $this->assertStringNotContainsString($restrictedBooking->reference, $invalid->json('components.0.effects.html'));
    }

    public function test_livewire_discards_a_branch_after_its_permission_is_revoked(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $business = $this->business('revoked-branch');
        $north = $this->branch($business, 'Revoked Branch');
        $south = $this->branch($business, 'Retained South');
        $west = $this->branch($business, 'Retained West');
        $user = $this->member($business, BusinessRole::Manager, [$north, $south, $west]);
        $booking = $this->booking($north, $this->service($business), BookingStatus::Confirmed, '2026-11-06 08:00:00');
        $this->actingAs($user, 'web');
        $initial = $this->dashboardSnapshot($this->get($this->dashboardUrl($business))->assertOk());
        $selected = $this->applyFilters($initial, $north->getKey())->assertOk()
            ->json('components.0.snapshot');
        $this->assertSame($north->getKey(), $this->selectedBranch($selected));

        $this->member($business, BusinessRole::Manager, [$south, $west], $user);

        $result = $this->applyFilters($selected, $north->getKey())->assertOk();
        $this->assertNull($this->selectedBranch($result->json('components.0.snapshot')));
        $html = $result->json('components.0.effects.html');
        $this->assertStringNotContainsString('Revoked Branch', $html);
        $this->assertStringNotContainsString($booking->reference, $html);
    }

    public function test_livewire_applies_period_only_on_submit_and_validates_it_on_the_server(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $business = $this->business('period-filter');
        $branch = $this->branch($business, 'Main branch');
        $user = $this->member($business, BusinessRole::Owner);
        $user->forceFill(['preferred_locale' => 'en'])->save();
        $sameDay = $this->service($business, 'Same day service');
        $sameWeek = $this->service($business, 'Same week service');
        $sameMonth = $this->service($business, 'Same month service');
        $sameDayBooking = $this->booking($branch, $sameDay, BookingStatus::Confirmed, '2026-11-05 09:00:00');
        $sameWeekBooking = $this->booking($branch, $sameWeek, BookingStatus::Confirmed, '2026-11-06 09:00:00');
        $sameMonthBooking = $this->booking($branch, $sameMonth, BookingStatus::Confirmed, '2026-11-14 09:00:00');
        $this->actingAs($user, 'web');
        $page = $this->get($this->dashboardUrl($business))->assertOk();
        $initial = $this->dashboardSnapshot($page);
        $this->assertSame('3', $this->metric($page->getContent(), 'total'));

        $draft = $this->livewireUpdate($initial, ['filterData.period' => ReportPeriod::Today->value])->assertOk();
        $draftSnapshot = $draft->json('components.0.snapshot');
        $this->assertSame(ReportPeriod::CurrentMonth->value, $this->selectedPeriod($draftSnapshot));

        $today = $this->applyFilters($draftSnapshot, $branch->getKey(), ReportPeriod::Today->value)->assertOk();
        $todaySnapshot = $today->json('components.0.snapshot');
        $todayHtml = $today->json('components.0.effects.html');
        $this->assertSame(ReportPeriod::Today->value, $this->selectedPeriod($todaySnapshot));
        $this->assertSame('1', $this->metric($todayHtml, 'total'));
        $this->assertStringContainsString($sameDayBooking->reference, $todayHtml);
        $this->assertStringNotContainsString($sameWeekBooking->reference, $todayHtml);
        $this->assertStringNotContainsString($sameMonth->name, $todayHtml);

        $invalid = $this->applyFilters($todaySnapshot, $branch->getKey(), 'current_year')->assertOk();
        $invalidSnapshot = $invalid->json('components.0.snapshot');
        $this->assertSame(ReportPeriod::Today->value, $this->selectedPeriod($invalidSnapshot));
        $this->assertStringContainsString('Select a supported reporting period.', $invalid->json('components.0.effects.html'));

        $week = $this->applyFilters($invalidSnapshot, $branch->getKey(), ReportPeriod::CurrentWeek->value)->assertOk();
        $weekSnapshot = $week->json('components.0.snapshot');
        $weekHtml = $week->json('components.0.effects.html');
        $this->assertSame(ReportPeriod::CurrentWeek->value, $this->selectedPeriod($weekSnapshot));
        $this->assertSame('2', $this->metric($weekHtml, 'total'));
        $this->assertStringContainsString($sameWeekBooking->reference, $weekHtml);
        $this->assertStringNotContainsString($sameMonthBooking->reference, $weekHtml);

        $month = $this->applyFilters($weekSnapshot, $branch->getKey())->assertOk();
        $this->assertSame(ReportPeriod::CurrentMonth->value, $this->selectedPeriod($month->json('components.0.snapshot')));
        $this->assertSame('3', $this->metric($month->json('components.0.effects.html'), 'total'));
        $this->assertStringContainsString($sameMonthBooking->reference, $month->json('components.0.effects.html'));
    }

    public function test_business_switch_drops_previous_branch_and_does_not_mix_business_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $first = $this->business('first-business');
        $second = $this->business('second-business');
        $firstBranch = $this->branch($first, 'First Branch');
        $secondBranch = $this->branch($second, 'Second Branch');
        $firstBooking = $this->booking($firstBranch, $this->service($first), BookingStatus::Confirmed, '2026-11-06 08:00:00');
        $secondBooking = $this->booking($secondBranch, $this->service($second), BookingStatus::Confirmed, '2026-11-06 08:00:00');
        $user = $this->member($first, BusinessRole::Owner);
        $this->member($second, BusinessRole::Owner, user: $user);
        $this->actingAs($user, 'web');

        $firstPage = $this->get($this->dashboardUrl($first))->assertOk()
            ->assertSee($firstBooking->reference)->assertDontSee($secondBooking->reference);
        $firstSnapshot = $this->dashboardSnapshot($firstPage);
        $this->assertSame($firstBranch->getKey(), $this->selectedBranch($firstSnapshot));
        $firstToday = $this->applyFilters($firstSnapshot, $firstBranch->getKey(), ReportPeriod::Today->value)->assertOk();
        $this->assertSame(ReportPeriod::Today->value, $this->selectedPeriod($firstToday->json('components.0.snapshot')));

        $secondPage = $this->get($this->dashboardUrl($second))->assertOk()
            ->assertSee($secondBooking->reference)->assertDontSee($firstBooking->reference);
        $secondSnapshot = $this->dashboardSnapshot($secondPage);
        $this->assertSame($secondBranch->getKey(), $this->selectedBranch($secondSnapshot));
        $this->assertSame(ReportPeriod::CurrentMonth->value, $this->selectedPeriod($secondSnapshot));

        $foreign = $this->applyFilters($secondSnapshot, $firstBranch->getKey())->assertOk();
        $this->assertSame($secondBranch->getKey(), $this->selectedBranch($foreign->json('components.0.snapshot')));
        $this->assertStringNotContainsString($firstBooking->reference, $foreign->json('components.0.effects.html'));
    }

    public function test_livewire_rechecks_the_report_ability_after_a_role_change(): void
    {
        $business = $this->business('revoked-report-role');
        $branch = $this->branch($business, 'Assigned branch');
        $user = $this->member($business, BusinessRole::Manager, [$branch]);
        $this->actingAs($user, 'web');
        $page = $this->get($this->dashboardUrl($business))->assertOk()
            ->assertSee('data-dashboard-mode="analytics"', false);
        $snapshot = $this->dashboardSnapshot($page);

        $this->member($business, BusinessRole::Receptionist, [$branch], $user);

        $this->applyFilters($snapshot, $branch->getKey(), ReportPeriod::Today->value)->assertForbidden();
        $this->get($this->dashboardUrl($business))->assertOk()
            ->assertSee('data-dashboard-mode="operations"', false)
            ->assertDontSee('data-dashboard-mode="analytics"', false);
        $this->assertThrows(
            fn () => app(BookingReports::class)->forPeriod($user, $business, $branch->getKey(), ReportPeriod::Today),
            AuthorizationException::class,
        );
    }

    #[DataProvider('operationalRoles')]
    public function test_operational_roles_see_only_authorized_appointments_and_cannot_call_reports(BusinessRole $role): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC'));
        $business = $this->business('operations');
        $branch = $this->branch($business, 'Authorized Branch');
        $restricted = $this->branch($business, 'Restricted Branch');
        $user = $this->member($business, $role, [$branch]);
        $linked = $business->staff()->create(['name' => 'Linked staff', 'status' => 'active', 'user_id' => $user->getKey()]);
        $linked->locations()->attach($branch, ['is_active' => true]);
        $other = $business->staff()->create(['name' => 'Other staff', 'status' => 'active']);
        $service = $this->service($business);
        $today = $this->booking($branch, $service, BookingStatus::Confirmed, '2026-11-05 09:00:00', $linked);
        $upcoming = $this->booking($branch, $service, BookingStatus::Confirmed, '2026-11-06 09:00:00', $linked);
        $activeHold = $this->booking($branch, $service, BookingStatus::Held, '2026-11-05 09:30:00', $linked);
        $expiredHold = $this->booking($branch, $service, BookingStatus::Held, '2026-11-05 10:00:00', $linked);
        $expiredHold->update(['hold_expires_at' => now()]);
        $otherStaff = $this->booking($branch, $service, BookingStatus::Confirmed, '2026-11-06 10:00:00', $other);
        $restrictedBooking = $this->booking($restricted, $service, BookingStatus::Confirmed, '2026-11-06 11:00:00', $linked);
        $this->actingAs($user, 'web');

        $page = $this->get($this->dashboardUrl($business))->assertOk()
            ->assertSee('data-dashboard-mode="operations"', false)
            ->assertDontSee('data-dashboard-mode="analytics"', false)
            ->assertSee('مواعيد اليوم')
            ->assertSee('الحجوزات القادمة')
            ->assertSee($today->reference)
            ->assertSee($upcoming->reference)
            ->assertSee($activeHold->reference)
            ->assertDontSee($expiredHold->reference)
            ->assertDontSee($restrictedBooking->reference);
        if ($role === BusinessRole::Staff) {
            $page->assertDontSee($otherStaff->reference);
        } else {
            $page->assertSee($otherStaff->reference);
        }

        $this->applyFilters($this->dashboardSnapshot($page), $branch->getKey())->assertForbidden();
        $this->assertTrue(Gate::forUser($user)->denies(BookingReports::VIEW, [$business]));
        $this->expectException(AuthorizationException::class);
        app(BookingReports::class)->currentMonth($user, $business, $branch->getKey());
    }

    public static function operationalRoles(): array
    {
        return [
            'receptionist' => [BusinessRole::Receptionist],
            'staff' => [BusinessRole::Staff],
        ];
    }

    public function test_report_gate_and_service_enforce_both_business_and_branch_scope(): void
    {
        $business = $this->business('report-scope');
        $allowed = $this->branch($business, 'Allowed');
        $restricted = $this->branch($business, 'Restricted');
        $otherBusiness = $this->business('other-scope');
        $foreign = $this->branch($otherBusiness, 'Foreign');
        $user = $this->member($business, BusinessRole::Manager, [$allowed]);
        $gate = Gate::forUser($user);
        $reports = app(BookingReports::class);

        $this->assertTrue($gate->allows(BookingReports::VIEW, [$business, $allowed]));
        $this->assertTrue($gate->denies(BookingReports::VIEW, [$business, $restricted]));
        $this->assertTrue($gate->denies(BookingReports::VIEW, [$business, $foreign]));
        $this->assertTrue($gate->denies(BookingReports::VIEW, [$otherBusiness, $foreign]));
        $this->assertSame([$allowed->getKey()], $reports->locations($user, $business)->pluck('id')->all());

        foreach ([[$business, $restricted], [$business, $foreign], [$otherBusiness, $foreign]] as [$tenant, $branch]) {
            $this->assertThrows(fn () => $reports->currentMonth($user, $tenant, $branch->getKey()), AuthorizationException::class);
        }
    }

    public function test_aggregates_use_real_statuses_location_local_dates_and_constant_booking_queries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30:00', 'UTC'));
        $business = $this->business('aggregates');
        $branch = $this->branch($business, 'Riyadh');
        $otherBranch = $this->branch($business, 'Pacific', 'America/Los_Angeles');
        $user = $this->member($business, BusinessRole::Owner);
        $service = $this->service($business);
        $staff = $business->staff()->create(['name' => 'Assigned staff', 'status' => 'active']);

        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-10-31 20:59:59', $staff);
        $this->booking($branch, $service, BookingStatus::Held, '2026-10-31 21:00:00', $staff);
        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-11-01 20:59:59', $staff);
        $this->booking($branch, $service, BookingStatus::CheckedIn, '2026-11-01 21:00:00', $staff);
        $this->booking($branch, $service, BookingStatus::Completed, '2026-11-03 08:00:00', $staff);
        $this->booking($branch, $service, BookingStatus::Cancelled, '2026-11-04 08:00:00');
        $this->booking($branch, $service, BookingStatus::NoShow, '2026-11-05 08:00:00');
        $this->booking($branch, $service, BookingStatus::Expired, '2026-11-06 08:00:00');
        $this->booking($branch, $service, BookingStatus::Cancelled, '2026-11-30 20:59:59');
        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-11-30 21:00:00');
        $this->booking($otherBranch, $service, BookingStatus::Confirmed, '2026-11-01 06:59:59');
        $this->booking($otherBranch, $service, BookingStatus::Cancelled, '2026-11-01 07:00:00');

        $reports = app(BookingReports::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $report = $reports->currentMonth($user, $business, $branch->getKey());
            $reads = array_column(array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/\bfrom\s+`?bookings`?\b/i', $query['query']) === 1
            ), 'query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertGreaterThanOrEqual(3, count($reads));
        $this->assertLessThanOrEqual(4, count($reads));
        $this->assertGreaterThanOrEqual(3, count(preg_grep('/\bgroup\s+by\b/i', $reads)));
        $this->assertEquals([
            'total' => 6,
            'confirmed' => 1,
            'completed' => 1,
            'cancelled' => 2,
            'pending' => 0,
            'cancellation_rate' => 33.33,
        ], $report['statistics']);
        $this->assertCount(30, $report['trend']);
        $this->assertSame(1, $report['trend']['2026-11-01']);
        $this->assertSame(1, $report['trend']['2026-11-02']);
        $this->assertSame(0, $report['trend']['2026-11-07']);
        $this->assertSame(1, $report['trend']['2026-11-30']);
        $this->assertSame([
            ['id' => $service->getKey(), 'name' => $service->name, 'count' => 6],
        ], $report['services']);
        $staffCounts = array_column($report['staff'], 'count', 'id');
        $this->assertCount(2, $staffCounts);
        $this->assertSame(3, $staffCounts[$staff->getKey()]);
        $this->assertSame(3, $staffCounts['']);

        $pacific = $reports->currentMonth($user, $business, $otherBranch->getKey());
        $this->assertSame(1, $pacific['statistics']['total']);
        $this->assertSame(1, $pacific['statistics']['confirmed']);
        $this->assertCount(31, $pacific['trend']);
        $this->assertSame(1, $pacific['trend']['2026-10-31']);
        $this->assertArrayNotHasKey('2026-11-01', $pacific['trend']);
    }

    public function test_active_holds_are_separate_from_bookings_and_require_a_future_expiry(): void
    {
        $now = CarbonImmutable::parse('2026-11-05 08:00:00', 'UTC');
        $this->travelTo($now);
        $business = $this->business('hold-statuses');
        $branch = $this->branch($business, 'Riyadh');
        $user = $this->member($business, BusinessRole::Owner);
        $bookedService = $this->service($business, 'Booked service');
        $heldService = $this->service($business, 'Unconverted service');
        $holdStaff = $business->staff()->create(['name' => 'Unconverted staff', 'status' => 'active']);
        $confirmed = $this->booking($branch, $bookedService, BookingStatus::Confirmed, '2026-11-05 09:00:00');
        $this->booking($branch, $bookedService, BookingStatus::Cancelled, '2026-11-05 09:15:00');
        $this->booking($branch, $heldService, BookingStatus::Cancelled, '2026-11-05 09:30:00', $holdStaff)
            ->update(['confirmed_at' => null]);
        $this->booking($branch, $heldService, BookingStatus::Expired, '2026-11-05 10:00:00', $holdStaff);
        $this->booking($branch, $heldService, BookingStatus::Held, '2026-11-05 10:30:00', $holdStaff)
            ->update(['hold_expires_at' => $now->addSecond()]);
        $this->booking($branch, $heldService, BookingStatus::Held, '2026-11-05 11:00:00', $holdStaff)
            ->update(['hold_expires_at' => $now]);
        $this->booking($branch, $heldService, BookingStatus::Held, '2026-11-05 11:30:00', $holdStaff)
            ->update(['hold_expires_at' => $now->subSecond()]);
        $this->booking($branch, $heldService, BookingStatus::Held, '2026-11-05 12:00:00', $holdStaff)
            ->update(['hold_expires_at' => null]);
        $this->booking($branch, $heldService, BookingStatus::Held, '2026-11-06 12:30:00', $holdStaff)
            ->update(['hold_expires_at' => $now->addSecond()]);

        $report = app(BookingReports::class)->forPeriod($user, $business, $branch->getKey(), ReportPeriod::Today);
        $this->assertSame(2, $report['statistics']['total']);
        $this->assertSame(1, $report['statistics']['confirmed']);
        $this->assertSame(1, $report['statistics']['cancelled']);
        $this->assertSame(1, $report['statistics']['pending']);
        $this->assertEquals(50, $report['statistics']['cancellation_rate']);
        $this->assertSame(['2026-11-05' => 2], $report['trend']);
        $this->assertSame([$bookedService->getKey()], array_column($report['services'], 'id'));
        $this->assertSame([['id' => null, 'name' => null, 'count' => 2]], $report['staff']);
        $this->assertSame([$confirmed->getKey()], $report['upcoming']->modelKeys());
    }

    public function test_presets_use_location_local_calendar_bounds_and_exclusive_endings(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 21:30:00', 'UTC'));
        $business = $this->business('period-bounds');
        $branch = $this->branch($business, 'Riyadh');
        $user = $this->member($business, BusinessRole::Owner);
        $service = $this->service($business);
        $startsAt = [
            '2026-10-31 20:59:59',
            '2026-10-31 21:00:00',
            '2026-11-02 20:59:59',
            '2026-11-02 21:00:00',
            '2026-11-03 20:59:59',
            '2026-11-03 21:00:00',
            '2026-11-07 20:59:59',
            '2026-11-07 21:00:00',
            '2026-11-30 20:59:59',
            '2026-11-30 21:00:00',
        ];
        $bookings = array_map(fn (string $start): Booking => $this->booking($branch, $service, BookingStatus::Confirmed, $start), $startsAt);
        $reports = app(BookingReports::class);

        $today = $reports->forPeriod($user, $business, $branch->getKey(), ReportPeriod::Today);
        $this->assertSame('2026-11-03T00:00:00+03:00', $today['start']->toIso8601String());
        $this->assertSame('2026-11-04T00:00:00+03:00', $today['end']->toIso8601String());
        $this->assertSame(2, $today['statistics']['total']);
        $this->assertSame(['2026-11-03' => 2], $today['trend']);
        $this->assertSame([$bookings[4]->getKey()], $today['upcoming']->modelKeys());

        $week = $reports->forPeriod($user, $business, $branch->getKey(), ReportPeriod::CurrentWeek);
        $this->assertSame('2026-11-01T00:00:00+03:00', $week['start']->toIso8601String());
        $this->assertSame('2026-11-08T00:00:00+03:00', $week['end']->toIso8601String());
        $this->assertSame(6, $week['statistics']['total']);
        $this->assertCount(7, $week['trend']);
        $this->assertSame(1, $week['trend']['2026-11-01']);
        $this->assertSame(1, $week['trend']['2026-11-07']);
        $this->assertSame(array_map(fn (Booking $booking): string => $booking->getKey(), array_slice($bookings, 4, 3)), $week['upcoming']->modelKeys());

        $month = $reports->forPeriod($user, $business, $branch->getKey(), ReportPeriod::CurrentMonth);
        $this->assertSame('2026-11-01T00:00:00+03:00', $month['start']->toIso8601String());
        $this->assertSame('2026-12-01T00:00:00+03:00', $month['end']->toIso8601String());
        $this->assertSame(8, $month['statistics']['total']);
        $this->assertCount(30, $month['trend']);
        $this->assertSame(1, $month['trend']['2026-11-30']);
        $this->assertSame(array_map(fn (Booking $booking): string => $booking->getKey(), array_slice($bookings, 4, 5)), $month['upcoming']->modelKeys());
    }

    public function test_trend_uses_actual_dst_day_boundaries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
        $business = $this->business('dst');
        $branch = $this->branch($business, 'Los Angeles', 'America/Los_Angeles');
        $user = $this->member($business, BusinessRole::Owner);
        $service = $this->service($business);

        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-03-01 07:59:59');
        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-03-01 08:00:00');
        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-03-08 07:59:59');
        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-03-08 08:00:00');
        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-03-09 06:59:59');
        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-03-09 07:00:00');
        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-04-01 06:59:59');
        $this->booking($branch, $service, BookingStatus::Confirmed, '2026-04-01 07:00:00');

        $report = app(BookingReports::class)->currentMonth($user, $business, $branch->getKey());
        $this->assertSame(6, $report['statistics']['total']);
        $this->assertSame(1, $report['trend']['2026-03-01']);
        $this->assertSame(1, $report['trend']['2026-03-07']);
        $this->assertSame(2, $report['trend']['2026-03-08']);
        $this->assertSame(1, $report['trend']['2026-03-09']);
        $this->assertSame(1, $report['trend']['2026-03-31']);
    }

    public function test_top_services_are_ranked_and_an_empty_branch_returns_zeroes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2024-02-15 12:00:00', 'UTC'));
        $business = $this->business('top-services');
        $branch = $this->branch($business, 'Populated');
        $empty = $this->branch($business, 'Empty');
        $user = $this->member($business, BusinessRole::Owner);

        foreach (range(1, 6) as $count) {
            $service = $this->service($business, 'Service '.$count);
            foreach (range(1, $count) as $day) {
                $this->booking($branch, $service, BookingStatus::Confirmed, sprintf('2024-02-%02d 10:00:00', $day));
            }
        }

        $reports = app(BookingReports::class);
        $report = $reports->currentMonth($user, $business, $branch->getKey());
        $this->assertSame([6, 5, 4, 3, 2], array_column($report['services'], 'count'));
        $this->assertSame(['Service 6', 'Service 5', 'Service 4', 'Service 3', 'Service 2'], array_column($report['services'], 'name'));

        $emptyReport = $reports->currentMonth($user, $business, $empty->getKey());
        $this->assertSame(0, $emptyReport['statistics']['total']);
        $this->assertEquals(0, $emptyReport['statistics']['cancellation_rate']);
        $this->assertSame([], $emptyReport['services']);
        $this->assertSame([], $emptyReport['staff']);
        $this->assertCount(29, $emptyReport['trend']);
        $this->assertSame([0], array_values(array_unique($emptyReport['trend'])));
    }

    private function business(string $slug): Business
    {
        return Business::query()->create([
            'name' => 'Dashboard test '.$slug,
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
        return $business->services()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'is_active' => true,
        ]);
    }

    private function booking(Location $branch, Service $service, BookingStatus $status, string $utcStart, ?Staff $staff = null): Booking
    {
        $customer = $branch->business->customers()->create(['name' => 'Dashboard test customer', 'phone' => '0500000000']);
        $start = CarbonImmutable::parse($utcStart, 'UTC');
        $end = $start->addMinutes(30);

        return $branch->business->bookings()->create([
            'location_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff?->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => 'dashboard-'.str()->random(12),
            'status' => $status,
            'source' => 'test',
            'starts_at' => $start,
            'ends_at' => $end,
            'occupied_starts_at' => $start,
            'occupied_ends_at' => $end,
            'hold_expires_at' => $status === BookingStatus::Held ? $start->addMinutes(15) : null,
            'confirmed_at' => in_array($status, [BookingStatus::Held, BookingStatus::Expired], true) ? null : $start->subDay(),
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => 30,
        ]);
    }

    private function dashboardUrl(Business $business): string
    {
        return route('filament.admin.pages.dashboard', ['tenant' => $business]);
    }

    private function dashboardSnapshot(TestResponse $page): string
    {
        preg_match_all('/\bwire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES, 'UTF-8');
            $data = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            if (str_ends_with($data['memo']['name'], 'pages.dashboard')) {
                return $snapshot;
            }
        }

        $this->fail('The dashboard page did not render a signed Livewire snapshot.');
    }

    private function selectedBranch(string $snapshot): ?string
    {
        return json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data']['branchId'];
    }

    private function selectedPeriod(string $snapshot): string
    {
        return json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data']['period'];
    }

    private function metric(string $html, string $name): string
    {
        $page = new \DOMDocument;
        @$page->loadHTML($html);
        $xpath = new \DOMXPath($page);

        return trim($xpath->evaluate('string(//*[@data-dashboard-stat="'.$name.'"]//*[contains(@class, "fi-wi-stats-overview-stat-value")])'));
    }

    private function applyFilters(string $snapshot, string $branchId, string $period = ReportPeriod::CurrentMonth->value): TestResponse
    {
        return $this->livewireUpdate($snapshot, ['filterData.branch_id' => $branchId, 'filterData.period' => $period], 'applyFilters');
    }

    private function livewireUpdate(string $snapshot, array $updates, ?string $method = null): TestResponse
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => $method === null ? [] : [['path' => '', 'method' => $method, 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }
}
