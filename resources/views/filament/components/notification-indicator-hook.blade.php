@if (filament()->auth()->check() && filament()->getTenant() instanceof \App\Models\Business)
    <livewire:filament.notification-indicator />
@endif