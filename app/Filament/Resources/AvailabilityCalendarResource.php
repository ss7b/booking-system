<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AvailabilityCalendarResource\Pages\ManageAvailabilityCalendars;
use App\Models\AvailabilityCalendar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class AvailabilityCalendarResource extends TenantResource
{
    protected static ?string $model = AvailabilityCalendar::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

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
                Tables\Actions\DeleteAction::make()->visible(fn (AvailabilityCalendar $record): bool => ! $record->rules()->exists() && ! $record->overrides()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAvailabilityCalendars::route('/'),
        ];
    }
}
