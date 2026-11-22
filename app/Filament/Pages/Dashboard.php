<?php

namespace App\Filament\Pages;

use App\Domain\Reports\BookingReports;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportRange;
use App\Filament\Pages\Concerns\InteractsWithDetailedReports;
use App\Filament\Resources\BookingResource;
use App\Models\Business;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as FilamentDashboard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Dashboard extends FilamentDashboard
{
    use InteractsWithDetailedReports;
    use WithPagination;

    protected static string $view = 'filament.pages.dashboard';

    #[Locked]
    public ?string $businessId = null;

    #[Locked]
    public ?string $branchId = null;

    #[Locked]
    public string $period = ReportPeriod::CurrentMonth->value;

    #[Locked]
    public ?string $startDate = null;

    #[Locked]
    public ?string $endDate = null;

    #[Url(as: 'tab', except: 'dashboard', history: true)]
    public string $activeTab = 'dashboard';

    public array $filterData = [
        'period' => ReportPeriod::CurrentMonth->value,
        'branch_id' => null,
        'start_date' => null,
        'end_date' => null,
    ];

    protected ?array $branchOptions = null;

    private ?bool $canViewAnalytics = null;

    public function mount(): void
    {
        $this->assertActiveTab();
        $this->resetFiltersForTenant();
    }

    public function hydrate(): void
    {
        $this->branchOptions = null;
        $this->canViewAnalytics = null;

        if ($this->businessId !== $this->currentBusinessId()) {
            $this->resetFiltersForTenant();
        }

        $this->assertActiveTab();

        if ($this->branchId !== null && ! array_key_exists($this->branchId, $this->getBranchOptions())) {
            $this->clearBranch();
        }
    }

    public function updatedActiveTab(string $tab): void
    {
        $this->activeTab = $this->normalizeTab($tab);
    }

    public function selectTab(string $tab): void
    {
        abort_unless(in_array($tab, ['dashboard', 'reports'], true), 422);
        $this->activeTab = $this->normalizeTab($tab);
    }

    public function applyFilters(): void
    {
        $this->authorizeReportsAccess();

        $filters = $this->validate([
            'filterData.period' => ['bail', 'required', 'string', Rule::in($this->periodOptions())],
            'filterData.branch_id' => ['bail', 'required', 'string', Rule::in(array_keys($this->getBranchOptions()))],
            'filterData.start_date' => ['exclude_unless:filterData.period,custom', 'bail', 'required', 'date_format:Y-m-d'],
            'filterData.end_date' => ['exclude_unless:filterData.period,custom', 'bail', 'required', 'date_format:Y-m-d', 'after_or_equal:filterData.start_date'],
        ], [
            'filterData.period.*' => __('dashboard.filters.invalid_period'),
            'filterData.branch_id.*' => __('dashboard.filters.invalid_branch'),
            'filterData.start_date.*' => __('reports.filters.invalid_start'),
            'filterData.end_date.*' => __('reports.filters.invalid_end'),
        ])['filterData'];

        if ($filters['period'] === 'custom') {
            try {
                ReportRange::custom($filters['start_date'], $filters['end_date']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['filterData.end_date' => __('reports.filters.invalid_end')]);
            }
        }

        $this->branchId = $filters['branch_id'];
        $this->period = $filters['period'];
        $this->startDate = $filters['period'] === 'custom' ? $filters['start_date'] : null;
        $this->endDate = $filters['period'] === 'custom' ? $filters['end_date'] : null;
        $this->filterData['start_date'] = $this->startDate;
        $this->filterData['end_date'] = $this->endDate;
        $this->resetReportPages();
    }

    public function getTitle(): string
    {
        if ($this->activeTab === 'reports' && $this->canViewAnalytics()) {
            return __('reports.title');
        }

        return __('dashboard.'.($this->canViewAnalytics() ? 'analytics' : 'operations'));
    }

    protected function getHeaderActions(): array
    {
        $business = Filament::getTenant();
        if (! $business instanceof Business || ! BookingResource::canAccess()) {
            return [];
        }

        return [
            Action::make('bookingCalendar')
                ->label(__('bookings.calendar.open_calendar'))
                ->icon('heroicon-m-calendar-days')
                ->url(BookingResource::getUrl('index', ['tenant' => $business, 'view' => 'calendar'])),
        ];
    }

    protected function getViewData(): array
    {
        $this->assertActiveTab();
        $analytics = $this->canViewAnalytics();
        $branches = $analytics ? $this->getBranchOptions() : [];
        $business = Filament::getTenant();
        $user = Filament::auth()->user();
        $report = null;
        $reportsPanel = null;

        if ($analytics && $this->branchId !== null && $user instanceof User && $business instanceof Business) {
            try {
                $reports = app(BookingReports::class);
                $range = $this->reportRange();

                if ($this->activeTab === 'dashboard') {
                    $report = $reports->forRange($user, $business, $this->branchId, $range);
                } else {
                    $reportsPanel = $this->getReportsPanelData($reports, $user, $business, $range);
                }
            } catch (AuthorizationException) {
                $this->authorizeReportsAccess();
                $this->clearBranch();
                $this->branchOptions = null;
                $branches = $this->getBranchOptions();
            }
        }

        return [
            'activeTab' => $this->activeTab,
            'analytics' => $analytics,
            'canViewReports' => $analytics,
            'branches' => $branches,
            'periodOptions' => $this->periodOptions(),
            'reportPeriod' => ReportPeriod::tryFrom($this->period),
            'report' => $report,
            'reportsPanel' => $reportsPanel,
            'business' => $business,
            'branchId' => $this->branchId,
            'period' => $this->period,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'appliedSearch' => $this->appliedSearch,
            'section' => $this->section,
        ];
    }

    private function canViewAnalytics(): bool
    {
        $user = Filament::auth()->user();
        $business = Filament::getTenant();

        return $this->canViewAnalytics ??= $user instanceof User
            && $business instanceof Business
            && Gate::forUser($user)->allows(BookingReports::VIEW, $business);
    }

    private function authorizeReportsAccess(): void
    {
        $user = Filament::auth()->user();
        $business = Filament::getTenant();
        abort_unless($user instanceof User && $business instanceof Business, 403);
        Gate::forUser($user)->authorize(BookingReports::VIEW, $business);
    }

    private function assertActiveTab(): void
    {
        $this->activeTab = $this->normalizeTab($this->activeTab);
    }

    private function normalizeTab(string $tab): string
    {
        if (! in_array($tab, ['dashboard', 'reports'], true)) {
            return 'dashboard';
        }

        if ($tab === 'reports') {
            $this->authorizeReportsAccess();
        }

        return $tab;
    }

    private function getBranchOptions(): array
    {
        if (! $this->canViewAnalytics()) {
            return [];
        }

        return $this->branchOptions ??= app(BookingReports::class)->locations(
            Filament::auth()->user(),
            Filament::getTenant(),
        )->orderBy('name')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }

    private function reportRange(): ReportRange
    {
        return $this->period === 'custom'
            ? ReportRange::custom($this->startDate, $this->endDate)
            : ReportRange::preset(ReportPeriod::from($this->period));
    }

    private function periodOptions(): array
    {
        return [...array_column(ReportPeriod::cases(), 'value'), 'custom'];
    }

    private function currentBusinessId(): ?string
    {
        $business = Filament::getTenant();

        return $business instanceof Business ? (string) $business->getKey() : null;
    }

    private function resetFiltersForTenant(): void
    {
        $this->businessId = $this->currentBusinessId();
        $this->period = ReportPeriod::CurrentMonth->value;
        $this->startDate = $this->endDate = null;
        $this->filterData = [
            'period' => $this->period,
            'branch_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];
        $this->resetValidation();

        $branches = $this->getBranchOptions();
        $this->branchId = count($branches) === 1 ? (string) array_key_first($branches) : null;
        $this->filterData['branch_id'] = $this->branchId;
    }

    private function clearBranch(): void
    {
        $this->branchId = null;
        $this->filterData['branch_id'] = null;
        $this->addError('filterData.branch_id', __('reports.filters.revoked'));
    }
}
