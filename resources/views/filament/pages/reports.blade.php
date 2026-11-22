<div class="grid min-w-0 gap-6" data-reports-content data-report-section="{{ $section }}">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="overflow-x-auto">
            <x-filament::tabs :label="__('reports.title')">
                @foreach ($sections as $reportSection)
                    <x-filament::tabs.item
                        :active="$section === $reportSection"
                        wire:click="showSection('{{ $reportSection }}')"
                        wire:loading.attr="disabled"
                        wire:target="showSection"
                        id="report-tab-{{ $reportSection }}"
                        :aria-controls="'report-section-'.$reportSection"
                    >
                        {{ __('reports.sections.'.$reportSection) }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        </div>

        <form method="POST" action="{{ route('filament.admin.reports.export', ['tenant' => $business, 'section' => $section]) }}">
            @csrf
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
            <input type="hidden" name="period" value="{{ $period }}">
            @if ($period === 'custom')
                <input type="hidden" name="start_date" value="{{ $startDate }}">
                <input type="hidden" name="end_date" value="{{ $endDate }}">
            @endif
            @if ($section === 'bookings' && $appliedSearch !== '')
                <input type="hidden" name="search" value="{{ $appliedSearch }}">
            @endif
            <x-filament::button type="submit" icon="heroicon-m-arrow-down-tray">
                {{ __('reports.exports.button') }}
            </x-filament::button>
        </form>
    </div>

    @php
        $formatDate = fn (string $date): string => \Carbon\CarbonImmutable::parse($date)
            ->locale(app()->getLocale())->translatedFormat('j M Y');
        $activePeriod = $period === 'custom'
            ? __('reports.filters.date_range', ['start' => $formatDate($startDate), 'end' => $formatDate($endDate)])
            : __('dashboard.filters.'.$period);
    @endphp
    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
        {{ __('reports.filters.active', ['branch' => $branches[$branchId], 'period' => $activePeriod]) }}
    </p>

    @if ($section === 'bookings')
        @include('filament.pages.reports.booking-summary', ['overview' => $result['overview'], 'days' => $result['days']])
        @include('filament.pages.reports.booking-table', ['rows' => $result['rows'], 'bookingListUrl' => $result['bookingListUrl']])
    @else
        @include('filament.pages.reports.breakdown-table', ['rows' => $result['rows']])
    @endif
</div>
