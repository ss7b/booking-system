<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AvailabilityCalendarResource\Pages\ManageAvailabilityCalendars;
use App\Models\AvailabilityCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AvailabilityCalendarResource extends TenantResource
{
    protected static ?string $model = AvailabilityCalendar::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['rules', 'overrides']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(150),
                Forms\Components\TextInput::make('timezone')->required()->maxLength(64)->placeholder('Asia/Riyadh'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('timezone'),
                Tables\Columns\TextColumn::make('rules_count')->counts('rules')->label('Weekly rules'),
                Tables\Columns\TextColumn::make('overrides_count')->counts('overrides')->label('Date overrides'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (AvailabilityCalendar $record): bool => (int) $record->rules_count === 0 && (int) $record->overrides_count === 0)
                    ->before(fn (AvailabilityCalendar $record) => abort_unless(
                        ! $record->rules()->exists() && ! $record->overrides()->exists(),
                        422,
                        'This availability calendar has rules or overrides and cannot be deleted.',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAvailabilityCalendars::route('/'),
        ];
    }
}
