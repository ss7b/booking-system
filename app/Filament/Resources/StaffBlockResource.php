<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffBlockResource\Pages\ManageStaffBlocks;
use App\Models\StaffBlock;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class StaffBlockResource extends TenantResource
{
    protected static ?string $model = StaffBlock::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('staff_id')
                    ->relationship('staff', 'name', modifyQueryUsing: fn ($query) => $query->where('business_id', Filament::getTenant()?->getKey()))
                    ->searchable()->preload()->required(),
                Forms\Components\DateTimePicker::make('starts_at')->required(),
                Forms\Components\DateTimePicker::make('ends_at')->after('starts_at')->required(),
                Forms\Components\Select::make('type')->options([
                    'leave' => 'Leave', 'maintenance' => 'Maintenance', 'external_busy' => 'External busy',
                    'manual' => 'Manual', 'emergency' => 'Emergency', 'break' => 'Break',
                ])->required(),
                Forms\Components\TextInput::make('reason')->maxLength(255)->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('staff.name')->label('Staff')->sortable(),
                Tables\Columns\TextColumn::make('starts_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('ends_at')->dateTime(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('reason')->limit(40),
            ])
            ->filters([Tables\Filters\SelectFilter::make('type')->options([
                'leave' => 'Leave', 'maintenance' => 'Maintenance', 'external_busy' => 'External busy',
                'manual' => 'Manual', 'emergency' => 'Emergency', 'break' => 'Break',
            ])])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStaffBlocks::route('/'),
        ];
    }
}
