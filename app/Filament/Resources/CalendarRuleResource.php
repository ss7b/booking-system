<?php

namespace App\Filament\Resources;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Authorization\BusinessMembershipRequestContext;
use App\Filament\Resources\CalendarRuleResource\Pages\ManageCalendarRules;
use App\Filament\Resources\Concerns\TranslatesResourceLabels;
use App\Models\Business;
use App\Models\CalendarRule;
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
use Illuminate\Validation\Rules\Unique;

class CalendarRuleResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = CalendarRule::class;

    protected static bool $isScopedToTenant = false;

    public static function getEloquentQuery(): Builder
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $business || ! $user) {
            return CalendarRule::query()->whereRaw('1 = 0');
        }

        BusinessMembershipRequestContext::beginForResource('calendar_rules', $user, $business);

        return CalendarRule::query()->whereIn('calendar_id', app(BusinessAccess::class)->calendars($user, $business)->select('id'));
    }

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('calendar_id')
                    ->options(fn () => app(BusinessAccess::class)->calendars(Filament::auth()->user(), Filament::getTenant())->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required()
                    ->rules([AccessibleCalendarId::forScheduling()]),
                Forms\Components\Select::make('day_of_week')->options([
                    0 => __('calendars.weekdays.sunday'),
                    1 => __('calendars.weekdays.monday'),
                    2 => __('calendars.weekdays.tuesday'),
                    3 => __('calendars.weekdays.wednesday'),
                    4 => __('calendars.weekdays.thursday'),
                    5 => __('calendars.weekdays.friday'),
                    6 => __('calendars.weekdays.saturday'),
                ])->required(),
                Forms\Components\TimePicker::make('start_time')
                    ->seconds(false)
                    ->required()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('calendar_id', $get('calendar_id'))
                            ->where('day_of_week', $get('day_of_week'))
                            ->where('end_time', $get('end_time')),
                    )
                    ->validationMessages(['unique' => __('messages.calendar_rule_exists')]),
                Forms\Components\TimePicker::make('end_time')->seconds(false)->after('start_time')->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('calendar.name')->label('Calendar')->sortable(),
                Tables\Columns\TextColumn::make('day_of_week')->formatStateUsing(fn (int $state): string => [
                    __('calendars.weekdays.sunday'),
                    __('calendars.weekdays.monday'),
                    __('calendars.weekdays.tuesday'),
                    __('calendars.weekdays.wednesday'),
                    __('calendars.weekdays.thursday'),
                    __('calendars.weekdays.friday'),
                    __('calendars.weekdays.saturday'),
                ][$state] ?? __('calendars.weekdays.unknown'))->sortable(),
                Tables\Columns\TextColumn::make('start_time')->time('H:i'),
                Tables\Columns\TextColumn::make('end_time')->time('H:i'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('calendar_id')->options(fn () => app(BusinessAccess::class)->calendars(Filament::auth()->user(), Filament::getTenant())->orderBy('name')->pluck('name', 'id'))])
            ->emptyStateHeading(__('resources.calendar_rule.empty_state.heading'))
            ->emptyStateDescription(fn (): ?string => static::hasAccessibleCalendars()
                ? __('resources.calendar_rule.empty_state.description')
                : __('resources.calendar_rule.empty_state.no_calendar'))
            ->emptyStateActions([
                Tables\Actions\Action::make('manage_calendars')
                    ->label(__('resources.calendar_rule.empty_state.manage_calendars'))
                    ->icon('heroicon-o-calendar')
                    ->url(fn (): string => AvailabilityCalendarResource::getUrl('index'))
                    ->visible(fn (): bool => static::canManageCalendars()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->modalHeading(__('filament-actions::edit.single.label')),
                Tables\Actions\DeleteAction::make()->modalHeading(__('filament-actions::delete.single.label')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCalendarRules::route('/'),
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
