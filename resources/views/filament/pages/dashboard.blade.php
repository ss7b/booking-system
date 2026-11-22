<x-filament-panels::page class="fi-dashboard-page">
    <div class="grid min-w-0 gap-6" data-dashboard-page>
        @if ($canViewReports)
            <div class="overflow-x-auto">
                <x-filament::tabs :label="__('dashboard.tabs.label')">
                    @foreach (['dashboard', 'reports'] as $tab)
                        <x-filament::tabs.item
                            :active="$activeTab === $tab"
                            wire:click="selectTab('{{ $tab }}')"
                            wire:loading.attr="disabled"
                            wire:target="selectTab"
                            id="dashboard-tab-{{ $tab }}"
                            :aria-controls="'dashboard-panel-'.$tab"
                        >
                            {{ __('dashboard.tabs.'.$tab) }}
                        </x-filament::tabs.item>
                    @endforeach
                </x-filament::tabs>
            </div>

            @include('filament.pages.shared-report-filters')
        @endif

        <div
            wire:loading.delay
            wire:target="selectTab,applyFilters,showSection,searchBookings,sortBookings,nextPage,previousPage,gotoPage,setPage"
            role="status"
            class="text-sm text-gray-500 dark:text-gray-400"
        >
            {{ __('reports.loading') }}
        </div>

        @if ($activeTab === 'dashboard')
            <div
                id="dashboard-panel-dashboard"
                @if ($canViewReports)
                role="tabpanel"
                aria-labelledby="dashboard-tab-dashboard"
                @endif
                data-dashboard-mode="{{ $analytics ? 'analytics' : 'operations' }}"
                wire:loading.class="opacity-50"
                wire:target="applyFilters,selectTab"
                class="grid gap-6"
            >
                @if ($analytics)
                    @if ($report !== null)
                        @include('filament.widgets.dashboard-analytics', [
                            'branchName' => $branches[$branchId],
                            'report' => $report,
                            'business' => $business,
                        ])
                    @elseif ($branches !== [])
                        <x-filament::section>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.filters.choose_to_view') }}</p>
                        </x-filament::section>
                    @endif
                @else
                    @livewire(\App\Filament\Widgets\OperationalDashboardWidget::class, [], key('operations-'.$businessId))
                @endif
            </div>
        @elseif ($activeTab === 'reports')
            <div
                id="dashboard-panel-reports"
                role="tabpanel"
                aria-labelledby="dashboard-tab-reports"
                data-reports-page
                wire:loading.class="opacity-50"
                wire:target="applyFilters,selectTab,showSection,searchBookings,sortBookings,nextPage,previousPage,gotoPage,setPage"
                class="grid min-w-0 gap-6"
            >
                @if ($branches === [])
                    <x-filament::section>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.filters.no_branches') }}</p>
                    </x-filament::section>
                @elseif ($branchId === null)
                    <x-filament::section>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.choose_branch') }}</p>
                    </x-filament::section>
                @elseif ($reportsPanel !== null)
                    @include('filament.pages.reports', $reportsPanel)
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
