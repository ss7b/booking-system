<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="space-y-4">
        <x-filament-panels::resources.tabs />

        <div class="flex flex-col gap-2 sm:flex-row" role="group" aria-label="{{ __('bookings.calendar.view') }}">
            <x-filament::button
                wire:click="setDisplayMode('table')"
                :color="$displayMode === 'table' ? 'primary' : 'gray'"
                aria-pressed="{{ $displayMode === 'table' ? 'true' : 'false' }}"
            >
                {{ __('bookings.calendar.table_view') }}
            </x-filament::button>

            <x-filament::button
                wire:click="setDisplayMode('calendar')"
                :color="$displayMode === 'calendar' ? 'primary' : 'gray'"
                aria-pressed="{{ $displayMode === 'calendar' ? 'true' : 'false' }}"
            >
                {{ __('bookings.calendar.calendar_view') }}
            </x-filament::button>
        </div>

        @if ($displayMode === 'calendar')
            @livewire(\App\Filament\Widgets\BookingCalendarWidget::class)
        @else
            <div class="flex flex-col gap-y-6">
                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

                {{ $this->table }}

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
