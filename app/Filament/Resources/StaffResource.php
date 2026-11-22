<?php

namespace App\Filament\Resources;

use App\Domain\Authorization\BusinessAccess;
use App\Filament\Resources\StaffResource\Pages\ManageStaff;
use App\Models\Business;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StaffResource extends TenantResource
{
    protected static ?string $model = Staff::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['services:id,name', 'locations:id,name'])
            ->withCount(['bookings', 'blocks']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(200),
                Forms\Components\TextInput::make('slug')->alphaDash()->maxLength(200)->nullable(),
                Forms\Components\TextInput::make('email')->email()->maxLength(255)->nullable(),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(50)->nullable(),
                Forms\Components\Select::make('user_id')
                    ->label('Optional login account')
                    ->options(function (): array {
                        $business = Filament::getTenant();

                        return $business instanceof Business
                            ? $business->users()->where('users.is_active', true)->orderBy('name')->get()->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name.' ('.$user->email.')'])->all()
                            : [];
                    })
                    ->searchable()->preload()->nullable(),
                Forms\Components\Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'])->required()->default('active'),
                Forms\Components\Select::make('calendar_id')
                    ->label('Availability calendar')
                    ->options(fn () => app(BusinessAccess::class)->calendars(Filament::auth()->user(), Filament::getTenant())->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->nullable(),
                Forms\Components\Select::make('services')
                    ->relationship('services', 'name', modifyQueryUsing: fn ($query) => $query->whereIn('services.id', app(BusinessAccess::class)->services(Filament::auth()->user(), Filament::getTenant())->select('id')))
                    ->multiple()->preload(),
                Forms\Components\Select::make('locations')
                    ->relationship('locations', 'name', modifyQueryUsing: fn ($query) => $query->whereIn('locations.id', app(BusinessAccess::class)->locations(Filament::auth()->user(), Filament::getTenant())->select('id')))
                    ->multiple()->preload(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('phone'),
                Tables\Columns\TextColumn::make('services.name')->label('Services')->listWithLineBreaks(),
                Tables\Columns\TextColumn::make('status')->badge(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'])])
            ->actions([
                Tables\Actions\EditAction::make()->modalHeading(__('filament-actions::edit.single.label')),
                Tables\Actions\DeleteAction::make()
                    ->modalHeading(__('filament-actions::delete.single.label'))
                    ->visible(fn (Staff $record): bool => (int) $record->bookings_count === 0 && (int) $record->blocks_count === 0)
                    ->before(fn (Staff $record) => abort_unless(
                        ! $record->bookings()->exists() && ! $record->blocks()->exists(),
                        422,
                        'This staff member has bookings or blocks and cannot be deleted.',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStaff::route('/'),
        ];
    }
}
