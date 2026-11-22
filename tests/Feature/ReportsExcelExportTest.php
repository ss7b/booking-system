<?php

namespace Tests\Feature;

use App\Domain\Authorization\ManageBusinessMemberships;
use App\Domain\Reports\BookingReports;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class ReportsExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_export_formula_like_booking_text_as_xlsx_strings(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00:00', 'UTC'));
        $business = $this->business('excel-formula');
        $location = $this->location($business, 'Formula branch');
        $service = $this->service($business, '=1+1');
        $staff = $business->staff()->create(['name' => '=SUM(1,1)', 'status' => 'active']);
        $owner = $this->member($business, BusinessRole::Owner);
        $booking = $this->booking(
            $business,
            $location,
            $service,
            BookingStatus::Confirmed,
            '2026-11-23 09:00:00',
            '=2+2',
            '=HYPERLINK("https://example.test","open")',
            $staff,
        );
        $this->actingAs($owner, 'web');
        $this->chooseLocale($business, 'en');

        $this->get(route('filament.admin.pages.dashboard', ['tenant' => $business]))
            ->assertOk()
            ->assertDontSee('Export Excel');
        $this->get(route('filament.admin.pages.dashboard', ['tenant' => $business, 'tab' => 'reports']))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Export Excel')
            ->assertSee('name="branch_id"', false);

        $this->assertTrue(app(BookingReports::class)->locations($owner, $business)->whereKey($location)->exists());
        $response = $this->export($business, $location, 'bookings');
        [$rows, $sheetXml, $sharedStrings] = $this->readWorkbook($response);

        $this->assertSame([
            'Booking reference', 'Customer', 'Service', 'Staff', 'Location',
            'Appointment date', 'Appointment time', 'Status',
        ], $rows[0]);
        $this->assertSame($booking->reference, $rows[1][0]);
        $this->assertSame('=HYPERLINK("https://example.test","open")', $rows[1][1]);
        $this->assertSame('=1+1', $rows[1][2]);
        $this->assertSame('=SUM(1,1)', $rows[1][3]);
        $this->assertSame('Formula branch', $rows[1][4]);
        $this->assertSame('12:00', $rows[1][6]);
        $this->assertSame('Confirmed', $rows[1][7]);
        $this->assertStringNotContainsString('<f', $sheetXml);
        $this->assertStringContainsString('HYPERLINK', $sheetXml);
    }

    public function test_manager_custom_range_exports_only_search_matches_and_rechecks_branch_access(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00:00', 'UTC'));
        $business = $this->business('excel-filter-scope');
        $allowed = $this->location($business, 'Allowed');
        $revoked = $this->location($business, 'Revoked');
        $otherBusiness = $this->business('excel-other-tenant');
        $otherLocation = $this->location($otherBusiness, 'Other tenant');
        $service = $this->service($business);
        $this->booking($business, $allowed, $service, BookingStatus::Confirmed, '2026-11-25 09:00:00', 'MATCH-ALLOWED');
        $this->booking($business, $allowed, $service, BookingStatus::Confirmed, '2026-11-26 09:00:00', 'NO-MATCH');
        $this->booking($business, $allowed, $service, BookingStatus::Confirmed, '2026-10-31 09:00:00', 'OUTSIDE-RANGE');
        $this->booking($business, $revoked, $service, BookingStatus::Confirmed, '2026-11-25 09:00:00', 'OTHER-BRANCH');
        $this->booking($otherBusiness, $otherLocation, $this->service($otherBusiness), BookingStatus::Confirmed, '2026-11-25 09:00:00', 'OTHER-TENANT');
        $manager = $this->member($business, BusinessRole::Manager, [$allowed, $revoked]);
        $this->actingAs($manager, 'web');

        $response = $this->export($business, $allowed, 'bookings', [
            'period' => 'custom',
            'start_date' => '2026-11-25',
            'end_date' => '2026-11-25',
            'search' => 'MATCH',
        ]);
        [$rows] = $this->readWorkbook($response);
        $this->assertCount(2, $rows);
        $this->assertSame('MATCH-ALLOWED', $rows[1][0]);

        $this->post($this->exportUrl($business, 'bookings'), [
            'branch_id' => $revoked->getKey(),
            'period' => 'current_month',
        ])->assertOk();

        app(ManageBusinessMemberships::class)->save(
            $business,
            $manager,
            BusinessRole::Manager,
            BranchAccessScope::Selected,
            [$allowed->getKey()],
        );

        $this->post($this->exportUrl($business, 'bookings'), [
            'branch_id' => $revoked->getKey(),
            'period' => 'current_month',
        ])->assertNotFound();

        $this->post($this->exportUrl($otherBusiness, 'bookings'), [
            'branch_id' => $otherLocation->getKey(),
            'period' => 'current_month',
        ])->assertNotFound();
    }

    public function test_breakdown_exports_match_visible_status_totals_and_keep_staffless_separate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00:00', 'UTC'));
        $business = $this->business('excel-breakdowns');
        $location = $this->location($business, 'Main branch');
        $service = $this->service($business, 'Consultation');
        $staff = $business->staff()->create(['name' => 'Roster staff', 'status' => 'active']);
        $this->booking($business, $location, $service, BookingStatus::Confirmed, '2026-11-23 09:00:00', 'CONFIRMED', staff: $staff);
        $this->booking($business, $location, $service, BookingStatus::Completed, '2026-11-23 10:00:00', 'COMPLETED', staff: $staff);
        $this->booking($business, $location, $service, BookingStatus::Cancelled, '2026-11-23 11:00:00', 'CANCELLED', staff: $staff);
        $this->booking($business, $location, $service, BookingStatus::Held, '2026-11-23 12:00:00', 'HELD');
        $owner = $this->member($business, BusinessRole::Owner);
        $this->actingAs($owner, 'web');
        $this->chooseLocale($business, 'en');

        [$serviceRows] = $this->readWorkbook($this->export($business, $location, 'services'));
        $this->assertSame([
            'Name', 'Total bookings', 'Confirmed', 'Completed', 'Cancelled', 'Active held', 'Cancellation rate (%)',
        ], $serviceRows[0]);
        $this->assertSame(['Consultation', 3, 1, 1, 1, 1, 33.33], $serviceRows[1]);

        [$staffRows] = $this->readWorkbook($this->export($business, $location, 'staff'));
        $this->assertSame('Roster staff', $staffRows[1][0]);
        $this->assertSame([3, 1, 1, 1, 0, 33.33], array_slice($staffRows[1], 1));
        $staffless = collect(array_slice($staffRows, 1))->firstWhere(0, 'Staffless');
        $this->assertNotNull($staffless);
        $this->assertEquals([0, 0, 0, 0, 1, 0.0], array_slice($staffless, 1));
    }

    public function test_empty_custom_report_is_a_valid_arabic_rtl_workbook(): void
    {
        $business = $this->business('excel-empty');
        $location = $this->location($business, 'Empty branch');
        $manager = $this->member($business, BusinessRole::Manager, [$location]);
        $this->actingAs($manager, 'web');
        $this->chooseLocale($business, 'ar');

        $response = $this->export($business, $location, 'bookings', [
            'period' => 'custom',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ]);
        [$rows, $sheetXml] = $this->readWorkbook($response);

        $this->assertCount(1, $rows);
        $this->assertSame('مرجع الحجز', $rows[0][0]);
        $this->assertStringContainsString('rightToLeft="true"', $sheetXml);
        $this->get(route('filament.admin.pages.dashboard', ['tenant' => $business, 'tab' => 'reports']))
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('تصدير Excel');
        $this->post($this->exportUrl($business, 'bookings'), [
            'branch_id' => $location->getKey(),
            'period' => 'custom',
            'start_date' => '2026-11-31',
            'end_date' => '2026-11-31',
        ])->assertSessionHasErrors('start_date');
    }

    public function test_large_booking_export_crosses_query_chunk_boundary_without_dropping_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00:00', 'UTC'));
        $business = $this->business('excel-large');
        $location = $this->location($business, 'Large branch');
        $service = $this->service($business);
        $customer = $business->customers()->create(['name' => 'Large report customer']);
        $start = CarbonImmutable::parse('2026-11-23 09:00:00', 'UTC');
        $now = now();
        $bookings = [];
        for ($index = 1; $index <= 501; $index++) {
            $bookings[] = [
                'id' => (string) str()->ulid(),
                'business_id' => $business->getKey(),
                'location_id' => $location->getKey(),
                'service_id' => $service->getKey(),
                'staff_id' => null,
                'customer_id' => $customer->getKey(),
                'reference' => sprintf('LARGE-%04d', $index),
                'status' => BookingStatus::Confirmed->value,
                'source' => 'test',
                'starts_at' => $start,
                'ends_at' => $start->addMinutes(30),
                'occupied_starts_at' => $start,
                'occupied_ends_at' => $start->addMinutes(30),
                'service_name_snapshot' => $service->name,
                'duration_snapshot' => 30,
                'confirmed_at' => $start->subDay(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($bookings, 250) as $chunk) {
            Booking::query()->insert($chunk);
        }

        $owner = $this->member($business, BusinessRole::Owner);
        $this->actingAs($owner, 'web');
        $this->chooseLocale($business, 'en');
        [$rows] = $this->readWorkbook($this->export($business, $location, 'bookings'));

        $this->assertCount(502, $rows);
        $this->assertSame('LARGE-0501', $rows[501][0]);
    }

    #[DataProvider('rolesWithoutReportAccess')]
    public function test_operational_roles_cannot_export_reports(BusinessRole $role): void
    {
        $business = $this->business('excel-denied-'.$role->value);
        $location = $this->location($business, 'Branch');
        $user = $this->member($business, $role, [$location]);
        $this->actingAs($user, 'web');

        $this->post($this->exportUrl($business, 'bookings'), [
            'branch_id' => $location->getKey(),
            'period' => 'current_month',
        ])->assertForbidden();
    }

    public static function rolesWithoutReportAccess(): array
    {
        return [
            'receptionist' => [BusinessRole::Receptionist],
            'staff' => [BusinessRole::Staff],
        ];
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

    private function location(Business $business, string $name): Location
    {
        return $business->locations()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ]);
    }

    private function service(Business $business, string $name = 'Consultation'): Service
    {
        return $business->services()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.str()->random(6),
            'duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'is_active' => true,
        ]);
    }

    /** @param list<Location> $locations */
    private function member(Business $business, BusinessRole $role, array $locations = []): User
    {
        $user = User::factory()->create();
        app(ManageBusinessMemberships::class)->save(
            $business,
            $user,
            $role,
            $role === BusinessRole::Owner || $locations === [] ? BranchAccessScope::All : BranchAccessScope::Selected,
            array_map(static fn (Location $location): string => (string) $location->getKey(), $locations),
        );

        return $user;
    }

    private function booking(
        Business $business,
        Location $location,
        Service $service,
        BookingStatus $status,
        string $startsAt,
        string $reference,
        string $customerName = 'Export customer',
        ?Staff $staff = null,
    ): Booking {
        $start = CarbonImmutable::parse($startsAt, 'UTC');
        $customer = $business->customers()->create(['name' => $customerName]);

        return $business->bookings()->create([
            'location_id' => $location->getKey(),
            'service_id' => $service->getKey(),
            'staff_id' => $staff?->getKey(),
            'customer_id' => $customer->getKey(),
            'reference' => $reference,
            'status' => $status,
            'source' => 'test',
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(30),
            'occupied_starts_at' => $start,
            'occupied_ends_at' => $start->addMinutes(30),
            'hold_expires_at' => $status === BookingStatus::Held ? now()->addDay() : null,
            'service_name_snapshot' => $service->name,
            'duration_snapshot' => 30,
            'confirmed_at' => $status === BookingStatus::Held ? null : $start->subDay(),
        ]);
    }

    private function export(Business $business, Location $location, string $section, array $filters = []): TestResponse
    {
        return $this->post($this->exportUrl($business, $section), [
            'branch_id' => $location->getKey(),
            'period' => 'current_month',
            ...$filters,
        ]);
    }

    private function exportUrl(Business $business, string $section): string
    {
        return route('filament.admin.reports.export', [
            'tenant' => $business,
            'section' => $section,
        ]);
    }

    private function chooseLocale(Business $business, string $locale): void
    {
        $this->from(route('filament.admin.pages.dashboard', ['tenant' => $business]))
            ->post('/admin/locale', ['locale' => $locale])
            ->assertRedirect(route('filament.admin.pages.dashboard', ['tenant' => $business]));
    }

    /** @return array{list<list<mixed>>, string, string} */
    private function readWorkbook(TestResponse $response): array
    {
        $response->assertOk()->assertDownload();
        $path = tempnam(sys_get_temp_dir(), 'reports-xlsx-');
        file_put_contents($path, $response->streamedContent());

        $reader = new Reader;
        try {
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }
            $reader->close();

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $sharedStrings = $zip->getFromName('xl/sharedStrings.xml') ?: '';
            $zip->close();
            $this->assertIsString($sheetXml);

            return [$rows, $sheetXml, $sharedStrings];
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
