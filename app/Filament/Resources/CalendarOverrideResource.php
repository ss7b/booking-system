<?php

namespace App\Filament\Resources;

use App\Domain\Authorization\BusinessAccess;
use App\Filament\Resources\CalendarOverrideResource\Pages\ManageCalendarOverrides;
use App\Filament\Resources\Concerns\TranslatesResourceLabels;
use App\Models\Business;
use App\Models\CalendarOverride;
use App\Models\User;
use App\Rules\AccessibleCalendarId;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CalendarOverrideResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = CalendarOverride::class;

    protected static bool $isScopedToTenant = false;

    public static function getEloquentQuery(): Builder
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $business || ! $user) {
            return CalendarOverride::query()->whereRaw('1 = 0');
        }

        return CalendarOverride::query()->whereIn('calendar_id', app(BusinessAccess::class)->calendars($user, $business)->select('id'));
    }

    protected static ?string $navigationIcon = 'heroicon-o-calendar-date-range';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('calendar_id')
                    ->options(fn () => app(BusinessAccess::class)->calendars(Filament::auth()->user(), Filament::getTenant())->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required()
                    ->rules([AccessibleCalendarId::forScheduling()]),
                Forms\Components\DatePicker::make('date')->required(),
                Forms\Components\Select::make('mode')->options([
                    'open' => __('Open'),
                    'closed' => __('Closed'),
                ])->live()->required(),
                Forms\Components\TimePicker::make('start_time')->seconds(false)->visible(fn (Get $get): bool => $get('mode') === 'open')->required(fn (Get $get): bool => $get('mode') === 'open'),
                Forms\Components\TimePicker::make('end_time')->seconds(false)->after('start_time')->visible(fn (Get $get): bool => $get('mode') === 'open')->required(fn (Get $get): bool => $get('mode') === 'open'),
                Forms\Components\TextInput::make('reason')->maxLength(255)->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('calendar.name')->label('Calendar')->sortable(),
                Tables\Columns\TextColumn::make('date')->date()->sortable(),
                Tables\Columns\TextColumn::make('mode')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('calendars.override_modes.'.$state)),
                Tables\Columns\TextColumn::make('start_time')->time('H:i'),
                Tables\Columns\TextColumn::make('end_time')->time('H:i'),
                Tables\Columns\TextColumn::make('reason')->limit(40),
            ])
            ->filters([Tables\Filters\SelectFilter::make('mode')->options([
                'open' => __('Open'),
                'closed' => __('Closed'),
            ])])
            ->emptyStateHeading(__('resources.calendar_override.empty_state.heading'))
            ->emptyStateDescription(fn (): ?string => static::hasAccessibleCalendars()
                ? __('resources.calendar_override.empty_state.description')
                : __('resources.calendar_override.empty_state.no_calendar'))
            ->emptyStateActions([
                Tables\Actions\Action::make('manage_calendars')
                    ->label(__('resources.calendar_override.empty_state.manage_calendars'))
                    ->icon('heroicon-o-calendar')
                    ->url(fn (): string => AvailabilityCalendarResource::getUrl('index'))
                    ->visible(fn (): bool => static::canManageCalendars()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCalendarOverrides::route('/'),
        ];
    }

    private static function hasAccessibleCalendars(): bool
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        return $business instanceof Business && $user instanceof User
            && app(BusinessAccess::class)->calendars($user, $business)->exists();
    }

    private static function canManageCalendars(): bool
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        return $business instanceof Business && $user instanceof User
            && app(BusinessAccess::class)->canManageBusinessSettings($user, $business);
    }
}
