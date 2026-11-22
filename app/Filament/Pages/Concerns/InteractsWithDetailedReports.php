<?php

namespace App\Filament\Pages\Concerns;

use App\Domain\Reports\BookingReports;
use App\Domain\Reports\ReportRange;
use App\Filament\Resources\BookingResource;
use App\Models\Business;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;

trait InteractsWithDetailedReports
{
    private const REPORT_SECTIONS = ['bookings', 'services', 'staff'];

    private const BOOKING_COLUMNS = ['reference', 'starts_at', 'service', 'staff', 'customer', 'status'];

    private const REPORT_PAGES = ['bookingsPage', 'daysPage', 'servicesPage', 'staffPage'];

    #[Locked]
    public string $section = 'bookings';

    #[Locked]
    public string $bookingSort = 'starts_at';

    #[Locked]
    public string $bookingDirection = 'desc';

    #[Locked]
    public string $appliedSearch = '';

    public ?string $bookingSearch = '';

    public function showSection(string $section): void
    {
        $this->authorizeReportsAccess();
        abort_unless(in_array($section, self::REPORT_SECTIONS, true), 422);

        $this->section = $section;
    }

    public function searchBookings(): void
    {
        $this->authorizeReportsAccess();
        $this->validate(['bookingSearch' => ['nullable', 'string', 'max:150']], [
            'bookingSearch.max' => __('reports.bookings.invalid_search'),
        ]);
        $this->appliedSearch = trim($this->bookingSearch ?? '');
        $this->resetPage('bookingsPage');
    }

    public function sortBookings(string $column): void
    {
        $this->authorizeReportsAccess();
        abort_unless(in_array($column, self::BOOKING_COLUMNS, true), 422);

        $this->bookingDirection = $this->bookingSort === $column && $this->bookingDirection === 'asc' ? 'desc' : 'asc';
        $this->bookingSort = $column;
        $this->resetPage('bookingsPage');
    }

    private function getReportsPanelData(BookingReports $reports, User $user, Business $business, ReportRange $range): ?array
    {
        if ($this->branchId === null) {
            return null;
        }

        return [
            'sections' => self::REPORT_SECTIONS,
            'bookingColumns' => self::BOOKING_COLUMNS,
            'result' => match ($this->section) {
                'bookings' => $this->bookingResult($reports, $user, $business, $range),
                'services' => ['rows' => $reports->services(
                    $user, $business, $this->branchId, $range, perPage: 15, page: $this->reportPageNumber('servicesPage'),
                )->setPageName('servicesPage')],
                'staff' => ['rows' => $reports->staff(
                    $user, $business, $this->branchId, $range, perPage: 15, page: $this->reportPageNumber('staffPage'),
                )->setPageName('staffPage')],
            },
        ];
    }

    private function resetReportPages(): void
    {
        foreach (self::REPORT_PAGES as $page) {
            $this->resetPage($page);
        }
    }

    private function bookingResult(BookingReports $reports, User $user, Business $business, ReportRange $range): array
    {
        $report = $reports->bookingReport(
            $user,
            $business,
            $this->branchId,
            $range,
            search: $this->appliedSearch,
            sort: $this->bookingSort,
            direction: $this->bookingDirection,
            perPage: 15,
            page: $this->reportPageNumber('bookingsPage'),
        );
        $overview = $report['overview'];
        $rows = $report['bookings']->setPageName('bookingsPage');
        $trend = $overview['trend'];
        $dayPage = min($this->reportPageNumber('daysPage'), max(1, (int) ceil(count($trend) / 7)));

        return [
            'overview' => $overview,
            'rows' => $rows,
            'days' => new LengthAwarePaginator(
                array_slice($trend, ($dayPage - 1) * 7, 7, true),
                count($trend),
                7,
                $dayPage,
                ['path' => request()->url(), 'pageName' => 'daysPage'],
            ),
            'bookingListUrl' => BookingResource::canAccess()
                ? BookingResource::getUrl('index', ['tenant' => $business])
                : null,
        ];
    }

    private function reportPageNumber(string $name): int
    {
        return filter_var($this->getPage($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    }
}
