<?php

namespace App\Domain\Reports;

use App\Enums\BookingStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportExcelExporter
{
    private const MAX_ROWS_PER_SHEET = 1_048_576;

    public function __construct(private readonly BookingReports $reports) {}

    public function download(
        User $user,
        Business $business,
        Location $location,
        string $section,
        ReportRange $range,
        ?string $search = null,
    ): StreamedResponse {
        Gate::forUser($user)->authorize(BookingReports::VIEW, $business);
        $this->authorizeLocation($user, $business, (string) $location->getKey());

        abort_unless(in_array($section, ['bookings', 'services', 'staff'], true), 404);

        $rows = match ($section) {
            'bookings' => $this->reports->bookingRowsForExport(
                $user,
                $business,
                (string) $location->getKey(),
                $range,
                $search,
            ),
            default => $this->reports->breakdownRowsForExport(
                $user,
                $business,
                (string) $location->getKey(),
                $range,
                $section,
            ),
        };

        $timezone = $location->timezone ?: ($business->timezone ?: config('app.timezone'));
        $filename = 'reports-'.$section.'-'.now($timezone)->format('Ymd-His').'.xlsx';
        $locale = App::getLocale();

        return response()->streamDownload(
            function () use ($user, $business, $location, $section, $rows, $locale): void {
                $previousLocale = App::getLocale();
                $previousCarbonLocale = Carbon::getLocale();
                App::setLocale($locale);
                Carbon::setLocale($locale);

                try {
                    Gate::forUser($user)->authorize(BookingReports::VIEW, $business);
                    $authorizedLocation = $this->authorizeLocation($user, $business, (string) $location->getKey());
                    $writer = new Writer;

                    try {
                        $writer->openToFile('php://output');
                        $headers = $this->headers($section);
                        $headerStyle = (new Style)->setFontBold();
                        $sheetName = __('reports.sections.'.$section);
                        $sheetNumber = 1;
                        $sheet = $writer->getCurrentSheet();
                        $this->configureSheet($sheet, $sheetName);
                        $writer->addRow($this->textRow($headers, $headerStyle));
                        $rowCount = 1;

                        foreach ($rows as $row) {
                            if ($rowCount >= self::MAX_ROWS_PER_SHEET) {
                                $sheetNumber++;
                                $sheet = $writer->addNewSheetAndMakeItCurrent();
                                $this->configureSheet($sheet, mb_substr($sheetName, 0, 27).' '.$sheetNumber);
                                $writer->addRow($this->textRow($headers, $headerStyle));
                                $rowCount = 1;
                            }

                            $writer->addRow($this->dataRow(
                                $section,
                                $row,
                                $authorizedLocation,
                                $business,
                            ));
                            $rowCount++;
                        }
                    } finally {
                        $writer->close();
                    }
                } finally {
                    App::setLocale($previousLocale);
                    Carbon::setLocale($previousCarbonLocale);
                }
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function authorizeLocation(User $user, Business $business, string $locationId): Location
    {
        $location = $this->reports->locations($user, $business)
            ->whereKey($locationId)
            ->first(['locations.id', 'locations.name', 'locations.timezone']);

        if (! $location instanceof Location) {
            throw new AuthorizationException;
        }

        $tenant = Filament::getTenant();
        if (! $tenant instanceof Business || ! $tenant->is($business)) {
            throw new AuthorizationException;
        }

        return $location;
    }

    /** @return list<string> */
    private function headers(string $section): array
    {
        return match ($section) {
            'bookings' => [
                __('reports.exports.columns.reference'),
                __('reports.exports.columns.customer'),
                __('reports.exports.columns.service'),
                __('reports.exports.columns.staff'),
                __('reports.exports.columns.location'),
                __('reports.exports.columns.appointment_date'),
                __('reports.exports.columns.appointment_time'),
                __('reports.exports.columns.status'),
            ],
            'services', 'staff' => [
                __('reports.exports.columns.name'),
                __('reports.exports.columns.total'),
                __('reports.exports.columns.confirmed'),
                __('reports.exports.columns.completed'),
                __('reports.exports.columns.cancelled'),
                __('reports.exports.columns.pending'),
                __('reports.exports.columns.cancellation_rate'),
            ],
        };
    }

    private function configureSheet(Sheet $sheet, string $name): void
    {
        $sheet->setName($name);
        $sheet->setSheetView(
            (new SheetView)->setRightToLeft(app()->getLocale() === 'ar'),
        );
    }

    /** @param list<string> $values */
    private function textRow(array $values, ?Style $style = null): Row
    {
        return new Row(
            array_map(static fn (string $value): StringCell => new StringCell($value, $style), $values),
            $style,
        );
    }

    private function dataRow(string $section, array|object $row, Location $location, Business $business): Row
    {
        if ($section === 'bookings') {
            $timezone = $location->timezone ?: ($business->timezone ?: config('app.timezone'));
            $appointment = CarbonImmutable::instance($row->starts_at)
                ->setTimezone($timezone)
                ->locale(app()->getLocale());

            return $this->typedRow([
                $row->reference,
                $row->customer_name ?? '—',
                $row->service_name_snapshot,
                $row->staff_name ?? __('dashboard.staff.staffless'),
                $location->name,
                $appointment->translatedFormat('j M Y'),
                $appointment->format('H:i'),
                $this->statusLabel($row->status->value),
            ], [0, 1, 2, 3, 4, 5, 6, 7]);
        }

        $name = $row['id'] === null
            ? __('dashboard.staff.staffless')
            : ($row['name'] ?? __('reports.breakdown.unnamed'));

        return $this->typedRow([
            $name,
            $row['total'],
            $row['confirmed'],
            $row['completed'],
            $row['cancelled'],
            $row['pending'],
            $row['cancellation_rate'],
        ], [0]);
    }

    /** @param array<string|int|float|null> $values
     * @param  list<int>  $textColumns
     */
    private function typedRow(array $values, array $textColumns): Row
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $cells[] = in_array($index, $textColumns, true)
                ? new StringCell((string) ($value ?? ''), null)
                : Cell::fromValue($value);
        }

        return new Row($cells);
    }

    private function statusLabel(string $status): string
    {
        return __(
            in_array($status, [BookingStatus::Held->value, BookingStatus::Cancelled->value], true)
                ? 'reports.statuses.'.$status
                : 'bookings.statuses.'.$status,
        );
    }
}
