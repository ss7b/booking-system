<?php

namespace App\Domain\Reports;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

final class ReportPdfExporter
{
    public function __construct(private readonly BookingReports $reports) {}

    public function render(
        User $user,
        Business $business,
        Location $location,
        string $section,
        ReportRange $range,
        ?string $search = null,
    ): string {
        $report = $this->reports->forPdf($user, $business, (string) $location->getKey(), $range, $section, $search);
        $language = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $rtl = $language === 'ar';
        $direction = $rtl ? 'rtl' : 'ltr';
        $title = __('reports.'.$section.'.title');
        $temporaryDirectory = storage_path('app/private/report-pdf-'.bin2hex(random_bytes(16)));

        File::makeDirectory($temporaryDirectory, 0700, true);

        try {
            $pdf = new Mpdf([
                'tempDir' => $temporaryDirectory,
                'mode' => 'utf-8',
                'format' => $section === 'bookings' ? 'A4-L' : 'A4',
                'default_font' => 'dejavusans',
                'margin_left' => 13,
                'margin_right' => 13,
                'margin_top' => 18,
                'margin_bottom' => 17,
                'margin_header' => 8,
                'margin_footer' => 7,
                'shrink_tables_to_fit' => 1,
            ]);
            $pdf->SetDirectionality($direction);
            $pdf->SetTitle($title);

            $pdf->WriteHTML(view('reports.pdf', [
                'businessName' => $this->displayName($business->name, $rtl),
                'branchName' => $this->displayName($report['location']->name, $rtl),
                'direction' => $direction,
                'language' => $language,
                'title' => $title,
                'section' => $section,
                'start' => $report['start']->format('Y-m-d'),
                'end' => $report['end']->utc()->subMicrosecond()->setTimezone($report['end']->getTimezone())->toDateString(),
                'generatedAt' => $report['generated_at']->format('Y-m-d H:i (P)'),
                'statistics' => $report['statistics'],
                'rows' => $this->tableRows($section, $report['rows'], $report['generated_at'], $rtl),
                'detailsOmitted' => $report['detailsOmitted'],
                'rowLimit' => BookingReports::PDF_MAX_ROWS,
                'detailsFiltered' => $section === 'bookings' && trim($search ?? '') !== '',
            ])->render());

            return $pdf->OutputBinaryData();
        } finally {
            unset($pdf);
            File::deleteDirectory($temporaryDirectory);
        }
    }

    private function tableRows(string $section, array $rows, CarbonImmutable $generatedAt, bool $rtl): array
    {
        if ($section === 'bookings') {
            return array_map(fn (Booking $booking): array => [
                $booking->reference,
                $this->displayName($booking->customer_name ?? '-', $rtl),
                $this->displayName($booking->service_name_snapshot, $rtl),
                $this->displayName($booking->staff_name ?? __('dashboard.staff.staffless'), $rtl),
                CarbonImmutable::instance($booking->starts_at)->setTimezone($generatedAt->getTimezone())->format('Y-m-d H:i'),
                __(match ($booking->status) {
                    BookingStatus::Held, BookingStatus::Cancelled => 'reports.statuses.'.$booking->status->value,
                    default => 'bookings.statuses.'.$booking->status->value,
                }),
            ], $rows);
        }

        return array_map(fn (array $row): array => [
            $row['id'] === null
                ? __('dashboard.staff.staffless')
                : $this->displayName($row['name'] ?? __('reports.breakdown.unnamed'), $rtl),
            $row['total'],
            $row['confirmed'],
            $row['completed'],
            $row['cancelled'],
            $row['cancellation_rate'].'%',
        ], $rows);
    }

    private function displayName(string $name, bool $rtl): string
    {
        if (! $rtl && ! preg_match('/[\p{Arabic}\p{Hebrew}]/u', $name)) {
            return $name;
        }

        // Isolate Latin runs so that mPDF keeps punctuation in order when a mixed name wraps.
        return preg_replace(
            '~[\[({+#@-]*[\p{Latin}0-9](?:[\p{Latin}\p{M}0-9 .,:_+/&@#()%{}\[\]-]*[\p{Latin}\p{M}0-9%)\]}])?~u',
            "\u{2066}$0\u{2069}",
            $name,
        ) ?? $name;
    }
}
