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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ReportsPdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_downloads_a4_pdfs_from_the_reports_tab_in_both_locales(): void
    {
        [$business, $branch, $service, $owner] = $this->context();
        $name = 'مركز الاستشارات المتخصصة للعناية المتكاملة بالأسرة وتطوير الخدمات الصحية';
        $business->update(['name' => $name]);
        $branch->update(['name' => 'فرع الرياض - Riyadh 02']);
        $service->update(['name' => $name.' | Consultation Pro (2026)']);
        $staff = $business->staff()->create(['name' => $name, 'status' => 'active']);
        $this->booking($branch, $service, 'BK-2026-0123-A', staff: $staff, customer: $name);
        $this->actingAs($owner, 'web');

        $dashboardUrl = route('filament.admin.pages.dashboard', ['tenant' => $business]);
        $reportsUrl = $dashboardUrl.'?tab=reports';
        $localizedBookings = [];
        foreach (['en' => ['ltr', 'Export PDF', 'Export Excel'], 'ar' => ['rtl', 'تصدير PDF', 'تصدير Excel']] as $locale => [$direction, $pdfButton, $excelButton]) {
            $this->from($reportsUrl)->post('/admin/locale', ['locale' => $locale])->assertRedirect($reportsUrl);
            $this->get($dashboardUrl)->assertOk()->assertDontSee($pdfButton);
            $this->get($reportsUrl)->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($pdfButton)
                ->assertSee($excelButton)
                ->assertSee($this->exportUrl($business, 'bookings'), false);

            foreach (['bookings', 'services', 'staff'] as $section) {
                $response = $this->export($business, $branch, $section);
                $response->assertDownload('reports-'.$section.'-20261125-110000.pdf')->assertHeader('Content-Language', $locale);
                [$pdf, $pages] = $this->assertPdf($response, landscape: $section === 'bookings');
                $this->assertSame(1, $pages);
                $title = __('reports.'.$section.'.title', locale: $locale);
                $this->assertStringContainsString('/Title ('."\xFE\xFF".mb_convert_encoding($title, 'UTF-16BE').')', $pdf);

                if ($section === 'bookings') {
                    $localizedBookings[$locale] = $pdf;
                }
            }
        }

        $this->assertNotSame($localizedBookings['en'], $localizedBookings['ar']);
    }

    public function test_manager_download_scopes_rows_and_rechecks_branch_and_tenant_permissions(): void
    {
        [$business, $allowed, $service] = $this->context();
        $revoked = $this->branch($business, 'Restricted branch');
        [$otherBusiness, $otherBranch, $otherService] = $this->context('pdf-other-tenant');
        $manager = $this->member($business, BusinessRole::Manager, [$allowed, $revoked]);
        $this->actingAs($manager, 'web');

        $this->booking($allowed, $service, 'MATCH-PDF-ALLOWED');
        $this->booking($allowed, $service, 'UNRELATED-PDF');
        $this->booking($revoked, $service, 'MATCH-PDF-OTHER-BRANCH');
        $this->booking($otherBranch, $otherService, 'MATCH-PDF-OTHER-TENANT');
        $this->booking($allowed, $service, 'MATCH-PDF-OUTSIDE-RANGE', '2026-11-26 21:00:00');

        $filters = [
            'period' => 'custom',
            'start_date' => '2026-11-25',
            'end_date' => '2026-11-26',
            'search' => 'MATCH-PDF',
        ];
        $this->assertPdf($this->export($business, $allowed, 'bookings', $filters));
        $report = app(BookingReports::class)->forPdf(
            $manager, $business, $allowed->getKey(), ReportRange::custom('2026-11-25', '2026-11-26'), 'bookings', 'MATCH-PDF',
        );
        $this->assertSame(['MATCH-PDF-ALLOWED'], array_column($report['rows'], 'reference'));
        $this->assertSame(2, $report['statistics']['total']);
        $this->assertPdf($this->export($business, $revoked, 'bookings'));

        $memberships = app(ManageBusinessMemberships::class);
        $memberships->save($business, $manager, BusinessRole::Manager, BranchAccessScope::Selected, [$allowed->getKey()]);

        $this->export($business, $revoked, 'bookings')->assertNotFound();
        $this->export($business, $otherBranch, 'bookings')->assertNotFound();
        $this->export($otherBusiness, $otherBranch, 'bookings')->assertNotFound();

        $memberships->save($otherBusiness, $manager, BusinessRole::Manager, BranchAccessScope::Selected, [$otherBranch->getKey()]);
        $memberships->remove($business, $manager);
        $this->export($business, $allowed, 'bookings')->assertNotFound();
    }

    #[DataProvider('restrictedRoles')]
    public function test_operations_only_roles_cannot_download_any_pdf(BusinessRole $role): void
    {
        [$business, $branch] = $this->context();
        $this->actingAs($this->member($business, $role, [$branch]), 'web');

        foreach (['bookings', 'services', 'staff'] as $section) {
            $this->export($business, $branch, $section)->assertForbidden();
        }
    }

    public static function restrictedRoles(): array
    {
        return [
            'receptionist' => [BusinessRole::Receptionist],
            'staff' => [BusinessRole::Staff],
        ];
    }

    public function test_pdf_route_requires_authentication_and_valid_filters(): void
    {
        [$business, $branch, , $owner] = $this->context();
        $this->export($business, $branch, 'bookings')->assertRedirect(route('filament.admin.auth.login'));

        $this->actingAs($owner, 'web');
        $this->get($this->exportUrl($business, 'bookings'))->assertMethodNotAllowed();
        $this->export($business, $branch, 'unexpected')->assertNotFound();

        $dashboardUrl = route('filament.admin.pages.dashboard', ['tenant' => $business, 'tab' => 'reports']);
        foreach ([
            'branch_id' => ['branch_id' => []],
            'period' => ['period' => 'unexpected'],
            'start_date' => ['period' => 'custom', 'start_date' => '2026-11-31', 'end_date' => '2026-11-30'],
            'end_date' => ['period' => 'custom', 'start_date' => '2026-11-25', 'end_date' => '2026-11-24'],
            'search' => ['search' => str_repeat('a', 151)],
        ] as $field => $filters) {
            $this->from($dashboardUrl)->export($business, $branch, 'bookings', $filters)
                ->assertRedirect($dashboardUrl)
                ->assertSessionHasErrors($field);
        }
    }

    public function test_presets_respect_local_date_boundaries_and_match_excel(): void
    {
        [$business, $branch, $service, $owner] = $this->context();
        $this->actingAs($owner, 'web');
        $this->booking($branch, $service, 'MONTH-START', '2026-10-31 21:00:00');
        $this->booking($branch, $service, 'WEEK-START', '2026-11-21 21:00:00');
        $this->booking($branch, $service, 'TODAY-START', '2026-11-24 21:00:00');
        $this->booking($branch, $service, 'TODAY-END', '2026-11-25 20:59:59');
        $this->booking($branch, $service, 'TOMORROW', '2026-11-25 21:00:00');
        $this->booking($branch, $service, 'NEXT-MONTH', '2026-11-30 21:00:00');

        $expected = [
            ReportPeriod::Today->value => ['TODAY-START', 'TODAY-END'],
            ReportPeriod::CurrentWeek->value => ['WEEK-START', 'TODAY-START', 'TODAY-END', 'TOMORROW'],
            ReportPeriod::CurrentMonth->value => ['MONTH-START', 'WEEK-START', 'TODAY-START', 'TODAY-END', 'TOMORROW'],
        ];
        $reports = app(BookingReports::class);
        foreach (ReportPeriod::cases() as $period) {
            $filters = ['period' => $period->value];
            $this->assertPdf($this->export($business, $branch, 'bookings', $filters));
            $workbook = $this->excelRows($this->export($business, $branch, 'bookings', $filters, pdf: false));
            $data = $reports->forPdf($owner, $business, $branch->getKey(), ReportRange::preset($period), 'bookings');
            $this->assertEqualsCanonicalizing($expected[$period->value], array_column($data['rows'], 'reference'));
            $this->assertSame(array_column($workbook, 0), array_column($data['rows'], 'reference'));
        }
    }

    public function test_pdf_data_matches_dashboard_reports_and_excel_for_held_and_staffless(): void
    {
        [$business, $branch, $primary, $owner] = $this->context();
        $secondary = $this->service($business, 'Secondary service');
        $staff = $business->staff()->create(['name' => 'Report staff', 'status' => 'active']);
        $this->booking($branch, $primary, 'CONFIRMED-PDF', status: BookingStatus::Confirmed, staff: $staff);
        $this->booking($branch, $primary, 'COMPLETED-PDF', status: BookingStatus::Completed, staff: $staff);
        $this->booking($branch, $primary, 'CANCELLED-PDF', status: BookingStatus::Cancelled, staff: $staff);
        $this->booking($branch, $primary, 'ACTIVE-HELD-PDF', status: BookingStatus::Held);
        $this->booking($branch, $primary, 'EXPIRED-HELD-PDF', status: BookingStatus::Held)
            ->update(['hold_expires_at' => now()->subSecond()]);
        $this->booking($branch, $primary, 'NEVER-CONFIRMED-PDF', status: BookingStatus::Cancelled)
            ->update(['confirmed_at' => null]);
        $this->booking($branch, $secondary, 'STAFFLESS-PDF');
        $this->actingAs($owner, 'web');
        $this->withSession(['filament_locale' => 'en']);

        $reports = app(BookingReports::class);
        $range = ReportRange::preset(ReportPeriod::CurrentMonth);
        $overview = $reports->forRange($owner, $business, $branch->getKey(), $range);
        $bookings = $reports->forPdf($owner, $business, $branch->getKey(), $range, 'bookings');
        $this->assertSame($overview['statistics'], $bookings['statistics']);
        $this->assertSame(4, $bookings['statistics']['total']);
        $this->assertSame(1, $bookings['statistics']['pending']);
        $this->assertSame(25.0, $bookings['statistics']['cancellation_rate']);
        $this->assertEqualsCanonicalizing([
            'CONFIRMED-PDF', 'COMPLETED-PDF', 'CANCELLED-PDF', 'ACTIVE-HELD-PDF', 'STAFFLESS-PDF',
        ], array_column($bookings['rows'], 'reference'));
        $this->assertSame(
            array_column($this->excelRows($this->export($business, $branch, 'bookings', pdf: false)), 0),
            array_column($bookings['rows'], 'reference'),
        );

        foreach (['services', 'staff'] as $section) {
            $this->assertPdf($this->export($business, $branch, $section));
            $data = $reports->forPdf($owner, $business, $branch->getKey(), $range, $section);
            $this->assertSame($overview['statistics'], $data['statistics']);
            $this->assertSame($reports->$section($owner, $business, $branch->getKey(), $range)->items(), $data['rows']);
            $pdfRows = array_map(static fn (array $row): array => [
                $row['id'] === null ? 'Staffless' : $row['name'],
                $row['total'], $row['confirmed'], $row['completed'], $row['cancelled'], $row['pending'], $row['cancellation_rate'],
            ], $data['rows']);
            $this->assertEquals($this->excelRows($this->export($business, $branch, $section, pdf: false)), $pdfRows);

            if ($section === 'services') {
                $this->assertSame([$primary->name, $secondary->name], array_column($pdfRows, 0));
                $this->assertSame([$primary->name, 3, 1, 1, 1, 1, 33.33], $pdfRows[0]);
            } else {
                $this->assertSame(['Staffless', 1, 1, 0, 0, 1, 0.0], $pdfRows[1]);
            }
        }
    }

    public function test_empty_custom_reports_are_valid_one_page_documents(): void
    {
        [$business, $branch, , $owner] = $this->context();
        $this->actingAs($owner, 'web');

        foreach (['bookings', 'services', 'staff'] as $section) {
            [, $pages] = $this->assertPdf($this->export($business, $branch, $section, [
                'period' => 'custom', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
            ]));
            $this->assertSame(1, $pages);
        }
    }

    public function test_pdf_spans_pages_and_excess_rows_remain_available_in_excel(): void
    {
        [$business, $branch, $service, $owner] = $this->context();
        $this->actingAs($owner, 'web');
        $this->insertBookings($branch, $service, 1, 66);

        [, $pages] = $this->assertPdf($this->export($business, $branch, 'bookings'));
        $this->assertGreaterThan(1, $pages);

        $this->insertBookings($branch, $service, 67, BookingReports::PDF_MAX_ROWS);
        $reports = app(BookingReports::class);
        $range = ReportRange::preset(ReportPeriod::CurrentMonth);
        $atLimit = $reports->forPdf($owner, $business, $branch->getKey(), $range, 'bookings');
        $this->assertCount(BookingReports::PDF_MAX_ROWS, $atLimit['rows']);
        $this->assertFalse($atLimit['detailsOmitted']);

        $this->insertBookings($branch, $service, BookingReports::PDF_MAX_ROWS + 1, BookingReports::PDF_MAX_ROWS + 1);
        $aboveLimit = $reports->forPdf($owner, $business, $branch->getKey(), $range, 'bookings');
        $this->assertTrue($aboveLimit['detailsOmitted']);
        $this->assertSame([], $aboveLimit['rows']);
        $this->assertSame(BookingReports::PDF_MAX_ROWS + 1, $aboveLimit['statistics']['total']);
        [, $summaryPages] = $this->assertPdf($this->export($business, $branch, 'bookings'));
        $this->assertSame(1, $summaryPages);

        $services = $reports->forPdf($owner, $business, $branch->getKey(), $range, 'services');
        $this->assertFalse($services['detailsOmitted']);
        $this->assertSame(BookingReports::PDF_MAX_ROWS + 1, $services['rows'][0]['total']);

        $rows = $this->excelRows($this->export($business, $branch, 'bookings', pdf: false));
        $this->assertCount(BookingReports::PDF_MAX_ROWS + 1, $rows);
        $this->assertContains('LARGE-PDF-'.(BookingReports::PDF_MAX_ROWS + 1), array_column($rows, 0));
    }

    /** @return array{Business, Location, Service, User} */
    private function context(string $slug = 'pdf-export'): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 08:00:00', 'UTC'));
        $business = Business::query()->create([
            'name' => $slug, 'slug' => $slug, 'timezone' => 'Asia/Riyadh', 'default_currency' => 'SAR', 'is_active' => true,
        ]);

        return [$business, $this->branch($business, 'Main branch'), $this->service($business, 'Primary service'), $this->member($business, BusinessRole::Owner)];
    }

    private function branch(Business $business, string $name): Location
    {
        return $business->locations()->create([
            'name' => $name, 'slug' => str()->slug($name), 'timezone' => 'Asia/Riyadh', 'is_active' => true,
        ]);
    }

    private function service(Business $business, string $name): Service
    {
        return $business->services()->create([
            'name' => $name, 'slug' => str()->slug($name), 'duration_minutes' => 30, 'slot_interval_minutes' => 30, 'is_active' => true,
        ]);
    }

    /** @param list<Location> $branches */
    private function member(Business $business, BusinessRole $role, array $branches = []): User
    {
        $user = User::factory()->create();
        app(ManageBusinessMemberships::class)->save(
            $business, $user, $role, $branches === [] ? BranchAccessScope::All : BranchAccessScope::Selected, array_column($branches, 'id'),
        );

        return $user;
    }

    private function booking(
        Location $branch,
        Service $service,
        string $reference,
        string $startsAt = '2026-11-25 09:00:00',
        BookingStatus $status = BookingStatus::Confirmed,
        ?Staff $staff = null,
        string $customer = 'Report customer',
    ): Booking {
        $start = CarbonImmutable::parse($startsAt, 'UTC');
        $customer = $branch->business->customers()->create(['name' => $customer]);

        return $branch->business->bookings()->create([
            ...$this->bookingAttributes($branch, $service, $reference, $start, $customer->getKey()),
            'staff_id' => $staff?->getKey(),
            'status' => $status,
            'hold_expires_at' => $status === BookingStatus::Held ? now()->addDay() : null,
            'confirmed_at' => $status === BookingStatus::Held ? null : $start->subDay(),
        ]);
    }

    private function insertBookings(Location $branch, Service $service, int $first, int $last): void
    {
        $customer = $branch->business->customers()->create(['name' => 'Large PDF customer']);
        $start = CarbonImmutable::parse('2026-11-25 09:00:00', 'UTC');
        foreach (array_chunk(range($first, $last), 200) as $indexes) {
            Booking::query()->insert(array_map(fn (int $index): array => [
                ...$this->bookingAttributes($branch, $service, 'LARGE-PDF-'.$index, $start, $customer->getKey()),
                'id' => (string) str()->ulid(),
                'status' => BookingStatus::Confirmed->value,
                'confirmed_at' => $start->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ], $indexes));
        }
    }

    private function bookingAttributes(Location $branch, Service $service, string $reference, CarbonImmutable $start, string $customerId): array
    {
        $end = $start->addMinutes(30);

        return [
            'business_id' => $branch->business_id, 'location_id' => $branch->getKey(), 'service_id' => $service->getKey(),
            'customer_id' => $customerId, 'reference' => $reference, 'source' => 'test',
            'starts_at' => $start, 'ends_at' => $end, 'occupied_starts_at' => $start, 'occupied_ends_at' => $end,
            'service_name_snapshot' => $service->name, 'duration_snapshot' => 30,
        ];
    }

    private function export(Business $business, Location $branch, string $section, array $filters = [], bool $pdf = true): TestResponse
    {
        return $this->post($this->exportUrl($business, $section, $pdf), [
            'branch_id' => $branch->getKey(), 'period' => 'current_month', ...$filters,
        ]);
    }

    private function exportUrl(Business $business, string $section, bool $pdf = true): string
    {
        return route('filament.admin.reports.export'.($pdf ? '.pdf' : ''), ['tenant' => $business, 'section' => $section]);
    }

    /** @return array{string, int} */
    private function assertPdf(TestResponse $response, ?bool $landscape = null): array
    {
        $response->assertOk()->assertDownload()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $content = $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : $response->getContent();
        $this->assertMatchesRegularExpression('/^%PDF-\d\.\d/', $content);
        $this->assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $content, $crossReference));
        $this->assertSame('xref', substr($content, (int) $crossReference[1], 4));
        $this->assertSame(1, preg_match('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+([\d.]+)\s+([\d.]+)/', $content, $mediaBox));
        $dimensions = [(float) $mediaBox[1], (float) $mediaBox[2]];
        if ($landscape !== null) {
            $this->assertSame($landscape, $dimensions[0] > $dimensions[1]);
        }
        sort($dimensions);
        $this->assertEqualsWithDelta(595.28, $dimensions[0], 1);
        $this->assertEqualsWithDelta(841.89, $dimensions[1], 1);
        $pages = preg_match_all('/\/Type\s*\/Page\b/', $content);
        $this->assertGreaterThan(0, $pages);

        return [$content, $pages];
    }

    private function excelRows(TestResponse $response): array
    {
        $response->assertOk()->assertDownload();
        $path = tempnam(sys_get_temp_dir(), 'reports-pdf-parity-');
        $reader = new Reader;

        try {
            file_put_contents($path, $response->streamedContent());
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }

            return array_slice($rows, 1);
        } finally {
            $reader->close();
            unlink($path);
        }
    }
}
