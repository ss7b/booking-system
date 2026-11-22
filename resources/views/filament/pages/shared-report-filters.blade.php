<x-filament::section :heading="__('dashboard.filters.title')">
    @if ($branches === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.filters.no_branches') }}</p>
    @else
        <form wire:submit="applyFilters" x-data>
            <x-filament::grid :md="3" class="items-end gap-4">
                <div class="grid gap-y-2">
                    <label for="dashboard-period" class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ __('dashboard.filters.period') }}
                    </label>
                    <x-filament::input.wrapper :valid="! $errors->has('filterData.period')">
                        <x-filament::input.select id="dashboard-period" wire:model="filterData.period">
                            @foreach ($periodOptions as $option)
                                <option value="{{ $option }}">
                                    {{ __($option === 'custom' ? 'reports.filters.custom' : 'dashboard.filters.'.$option) }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div class="grid gap-y-2">
                    <label for="dashboard-branch" class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ __('dashboard.filters.branch') }}
                    </label>
                    <x-filament::input.wrapper :valid="! $errors->has('filterData.branch_id')">
                        <x-filament::input.select id="dashboard-branch" wire:model="filterData.branch_id" required>
                            <option value="">{{ __('dashboard.filters.select_branch') }}</option>
                            @foreach ($branches as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                <div>
                    <x-filament::button type="submit" wire:target="applyFilters">
                        {{ __('dashboard.filters.apply') }}
                    </x-filament::button>
                </div>
            </x-filament::grid>

            <div x-show="$wire.filterData.period === 'custom'" x-cloak class="mt-4">
                <x-filament::grid :md="2" class="gap-4">
                    @foreach (['start_date', 'end_date'] as $field)
                        <div class="grid gap-y-2">
                            <label for="dashboard-{{ $field }}" class="text-sm font-medium text-gray-950 dark:text-white">
                                {{ __('reports.filters.'.$field) }}
                            </label>
                            <x-filament::input.wrapper :valid="! $errors->has('filterData.'.$field)">
                                <x-filament::input
                                    type="date"
                                    id="dashboard-{{ $field }}"
                                    wire:model="filterData.{{ $field }}"
                                    x-bind:required="$wire.filterData.period === 'custom'"
                                />
                            </x-filament::input.wrapper>
                        </div>
                    @endforeach
                </x-filament::grid>
            </div>

            @foreach ($errors->get('filterData.*') as $messages)
                @foreach ($messages as $message)
                    <p role="alert" class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @endforeach
            @endforeach

            <div wire:loading.delay wire:target="applyFilters" role="status" class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ __('reports.loading') }}
            </div>
        </form>
    @endif
</x-filament::section>
