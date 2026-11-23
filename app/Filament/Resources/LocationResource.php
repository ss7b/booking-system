<?php

namespace App\Filament\Resources;

use App\Domain\Authorization\BusinessAccess;
use App\Filament\Resources\LocationResource\Pages\ManageLocations;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\Rules\AccessibleCalendarId;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LocationResource extends TenantResource
{
    protected static ?string $model = Location::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('bookings');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(150),
                Forms\Components\TextInput::make('slug')->required()->alphaDash()->maxLength(150),
                Forms\Components\TextInput::make('timezone')->maxLength(64)->placeholder('Asia/Riyadh'),
                Forms\Components\Select::make('calendar_id')
                    ->label('Availability calendar')
                    ->options(function (): array {
                        $business = Filament::getTenant();
                        $user = Filament::auth()->user();

                        if (! $business instanceof Business || ! $user instanceof User) {
                            return [];
                        }

                        return app(BusinessAccess::class)
                            ->calendarsForManagement($user, $business)
                            ->orderBy('name')->pluck('name', 'id')->all();
                    })
                    ->searchable()
                    ->nullable()
                    ->rules([AccessibleCalendarId::forManagement()]),
                Forms\Components\Textarea::make('address')->columnSpanFull(),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(50),
                Forms\Components\Toggle::make('is_active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('timezone'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([Tables\Filters\TernaryFilter::make('is_active')])
            ->emptyStateDescription(__('resources.location.empty_state.description'))
            ->emptyStateActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Location $record): bool => (int) $record->bookings_count === 0)
                    ->before(fn (Location $record) => abort_unless(
                        ! $record->bookings()->exists(),
                        422,
                        'This location has bookings and cannot be deleted.',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLocations::route('/'),
        ];
    }
}
