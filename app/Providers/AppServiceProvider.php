<?php

namespace App\Providers;

use App\Domain\Notifications\CustomerNotificationService;
use App\Domain\Notifications\ProcessBookingNotification;
use App\Http\Middleware\SetFilamentLocale;
use Filament\Actions\Action;
use Filament\Forms\Components\Component as FormComponent;
use Filament\Infolists\Components\Entry;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware(SetFilamentLocale::class);
        FormComponent::configureUsing(fn (FormComponent $component) => $component->translateLabel());
        Column::configureUsing(fn (Column $column) => $column->translateLabel());
        BaseFilter::configureUsing(fn (BaseFilter $filter) => $filter->translateLabel());
        Entry::configureUsing(fn (Entry $entry) => $entry->translateLabel());
        Action::configureUsing(fn (Action $action) => $action->translateLabel());
        TableAction::configureUsing(fn (TableAction $action) => $action->translateLabel());

        foreach (['BookingHeld', 'BookingConfirmed', 'BookingRescheduled', 'BookingCancelled'] as $eventName) {
            Event::listen($eventName, function (array $payload) use ($eventName): void {
                app(ProcessBookingNotification::class)->handle($eventName, $payload);
                app(CustomerNotificationService::class)->processOutboxEvent($eventName, $payload);
            });
        }
    }
}
