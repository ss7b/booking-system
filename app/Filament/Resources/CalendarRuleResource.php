<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CalendarRuleResource\Pages\ManageCalendarRules;
use App\Models\AvailabilityCalendar;
use App\Models\CalendarRule;
use App\Domain\Authorization\BusinessAccess;
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
    protected static ?string $model = CalendarRule::class;

    protected static bool $isScopedToTenant = false;

    public static function getEloquentQuery(): Builder
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $business || ! $user) return CalendarRule::query()->whereRaw('1 = 0');

        return CalendarRule::query()->whereIn('calendar_id', app(BusinessAccess::class)->calendars($user, $business)->select('id'));
    }

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('calendar_id')
                    ->options(fn () => app(BusinessAccess::class)->calendars(Filament::auth()->user(), Filament::getTenant())->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required(),
                Forms\Components\Select::make('day_of_week')->options([
                    0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
                    4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
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
                    ->validationMessages(['unique' => 'This exact calendar rule already exists.']),
                Forms\Components\TimePicker::make('end_time')->seconds(false)->after('start_time')->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('calendar.name')->label('Calendar')->sortable(),
                Tables\Columns\TextColumn::make('day_of_week')->formatStateUsing(fn (int $state): string => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$state] ?? 'Unknown')->sortable(),
                Tables\Columns\TextColumn::make('start_time')->time('H:i'),
                Tables\Columns\TextColumn::make('end_time')->time('H:i'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('calendar_id')->options(fn () => AvailabilityCalendar::query()->where('business_id', Filament::getTenant()?->getKey())->pluck('name', 'id'))])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCalendarRules::route('/'),
        ];
    }
}
