<?php

namespace App\Filament\Pages;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Authorization\ManageBusinessMemberships as MembershipManager;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Staff;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageBusinessMemberships extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Business Setup';

    protected static ?string $navigationLabel = null;

    protected static string $view = 'filament.pages.manage-business-memberships';

    public static function getNavigationLabel(): string
    {
        return __('memberships.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.business_setup');
    }

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        return $business instanceof Business && $user
            && app(BusinessAccess::class)->canManageMemberships($user, $business);
    }

    public function getHeading(): string
    {
        return __('memberships.heading');
    }

    public function getSubheading(): ?string
    {
        return __('memberships.description');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addMember')
                ->label(__('memberships.add_member'))
                ->icon('heroicon-o-user-plus')
                ->modalHeading(__('memberships.add_member'))
                ->modalWidth('2xl')
                ->form($this->memberFormSchema(true))
                ->action(fn (array $data) => $this->addMember($data)),
        ];
    }

    public function mount(): void
    {
        $this->form->fill([
            'role' => BusinessRole::Receptionist->value,
            'access_scope' => BranchAccessScope::Selected->value,
            'location_ids' => [],
            'create_new_account' => false,
            'new_name' => '',
            'new_email' => '',
            'new_password' => '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                ...$this->memberFormSchema(true),
            ])
            ->statePath('data');
    }

    private function memberFormSchema(bool $allowCreateAccount): array
    {
        $schema = [];

        if ($allowCreateAccount) {
            $schema[] = Components\Checkbox::make('create_new_account')
                ->label(__('actions.new_user'))
                ->live();
            $schema[] = Components\TextInput::make('new_name')
                ->label(__('memberships.new_name'))
                ->required(fn (Get $get): bool => (bool) $get('create_new_account'))
                ->visible(fn (Get $get): bool => (bool) $get('create_new_account'));
            $schema[] = Components\TextInput::make('new_email')
                ->label(__('memberships.new_email'))
                ->email()
                ->required(fn (Get $get): bool => (bool) $get('create_new_account'))
                ->visible(fn (Get $get): bool => (bool) $get('create_new_account'));
            $schema[] = Components\TextInput::make('new_password')
                ->label(__('memberships.new_password'))
                ->password()
                ->required(fn (Get $get): bool => (bool) $get('create_new_account'))
                ->minLength(12)
                ->visible(fn (Get $get): bool => (bool) $get('create_new_account'));
        }

        $schema[] = Components\Select::make('user_id')
            ->label(__('actions.existing_user'))
            ->options(fn () => User::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name.' ('.$user->email.')']))
            ->searchable()
            ->preload()
            ->required(fn (Get $get): bool => $allowCreateAccount ? ! (bool) $get('create_new_account') : true)
            ->disabled(fn (Get $get): bool => $allowCreateAccount && (bool) $get('create_new_account'))
            ->visible(fn (): bool => true);

        $schema[] = Components\Select::make('role')
            ->label(__('memberships.role'))
            ->options(collect(BusinessRole::cases())->mapWithKeys(fn (BusinessRole $role): array => [$role->value => __('roles.'.$role->value)]))
            ->required()
            ->live();
        $schema[] = Components\Radio::make('access_scope')
            ->label(__('memberships.branch_access'))
            ->options([
                BranchAccessScope::All->value => __('roles.scopes.all'),
                BranchAccessScope::Selected->value => __('roles.scopes.selected'),
            ])
            ->required()
            ->live()
            ->disabled(fn (Get $get): bool => $get('role') === BusinessRole::Owner->value)
            ->visible(fn (Get $get): bool => $get('role') !== BusinessRole::Owner->value);
        $schema[] = Components\CheckboxList::make('location_ids')
            ->label(__('memberships.assigned_branches'))
            ->options(fn (): array => Filament::getTenant() instanceof Business
                ? Filament::getTenant()->locations()->orderBy('name')->pluck('name', 'id')->all()
                : [])
            ->columns(2)
            ->visible(fn (Get $get): bool => $get('access_scope') === BranchAccessScope::Selected->value
                && $get('role') !== BusinessRole::Owner->value);

        return $schema;
    }

    public function save(MembershipManager $memberships): void
    {
        $business = Filament::getTenant();
        abort_unless($business instanceof Business && self::canAccess(), 403);
        $data = $this->form->getState();

        $userId = $data['user_id'] ?? null;
        if (! empty($data['create_new_account'])) {
            $user = User::query()->firstOrCreate(
                ['email' => strtolower(trim((string) ($data['new_email'] ?? '')))],
                [
                    'name' => trim((string) ($data['new_name'] ?? '')),
                    'password' => $data['new_password'] ?? 'secret12345',
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            $userId = (string) $user->getKey();
        }

        abort_unless(! empty($userId), 422, __('messages.select_member'));

        $memberships->save(
            $business,
            User::query()->findOrFail($userId),
            BusinessRole::from($data['role']),
            BranchAccessScope::from($data['access_scope'] ?? BranchAccessScope::All->value),
            $data['location_ids'] ?? [],
        );

        Notification::make()->title(__('actions.membership_saved'))->success()->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $business = Filament::getTenant();

                return $business instanceof Business
                    ? $business->users()
                        ->with(['staffProfiles'])
                        ->select([
                            'users.*',
                            'business_user.role as membership_role',
                            'business_user.access_scope as membership_scope',
                        ])
                        ->orderBy('users.name')
                        ->getQuery()
                    : User::query()->whereRaw('1 = 0');
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('memberships.user'))
                    ->description(fn (User $record): ?string => $record->email)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('memberships.email'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('membership_role')
                    ->label(__('memberships.role'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => is_string($state) ? __('roles.'.$state) : ''),
                Tables\Columns\TextColumn::make('membership_scope')
                    ->label(__('memberships.branch_access'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => is_string($state) ? __('roles.scopes.'.$state) : ''),
                Tables\Columns\TextColumn::make('staff_profile')
                    ->label(__('memberships.staff'))
                    ->state(function (User $record): string {
                        $business = Filament::getTenant();
                        if (! $business instanceof Business) {
                            return '—';
                        }
                        $staff = $record->staffProfiles
                            ->first(fn (Staff $profile): bool => (string) $profile->business_id === (string) $business->getKey());

                        return $staff?->name ?? '—';
                    })
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label(__('memberships.role'))
                    ->options(collect(BusinessRole::cases())->mapWithKeys(fn (BusinessRole $role): array => [$role->value => __('roles.'.$role->value)]))
                    ->query(fn ($query, array $data) => isset($data['value'])
                        ? $query->where('business_user.role', $data['value'])
                        : $query),
            ])
            ->actions([
                Tables\Actions\Action::make('edit')
                    ->label(__('memberships.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading(__('memberships.edit_member'))
                    ->modalWidth('2xl')
                    ->fillForm(fn (User $record): array => $this->memberFormState($record))
                    ->form($this->memberFormSchema(false))
                    ->action(fn (array $data, User $record) => $this->updateMember($record, $data)),
                Tables\Actions\Action::make('remove')
                    ->label(__('memberships.remove'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('memberships.remove_member'))
                    ->modalDescription(__('memberships.remove_confirmation'))
                    ->action(fn (User $record) => $this->removeMember((string) $record->getKey(), app(MembershipManager::class))),
            ])
            ->emptyStateHeading(__('memberships.empty_heading'))
            ->emptyStateDescription(__('memberships.empty_description'))
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateActions([
                Tables\Actions\Action::make('add')
                    ->label(__('memberships.add_member'))
                    ->icon('heroicon-o-user-plus')
                    ->modalHeading(__('memberships.add_member'))
                    ->modalWidth('2xl')
                    ->form($this->memberFormSchema(true))
                    ->action(fn (array $data) => $this->addMember($data)),
            ]);
    }

    private function addMember(array $data, ?MembershipManager $memberships = null): void
    {
        $business = Filament::getTenant();
        abort_unless($business instanceof Business && self::canAccess(), 403);
        $memberships ??= app(MembershipManager::class);

        try {
            $userId = $data['user_id'] ?? null;
            if (! empty($data['create_new_account'])) {
                $user = User::query()->firstOrCreate(
                    ['email' => strtolower(trim((string) ($data['new_email'] ?? '')))],
                    [
                        'name' => trim((string) ($data['new_name'] ?? '')),
                        'password' => $data['new_password'] ?? 'secret12345',
                        'is_active' => true,
                        'email_verified_at' => now(),
                    ],
                );
                $userId = (string) $user->getKey();
            }

            abort_unless(! empty($userId), 422, __('messages.select_member'));
            $memberships->save(
                $business,
                User::query()->findOrFail($userId),
                BusinessRole::from($data['role']),
                BranchAccessScope::from($data['access_scope'] ?? BranchAccessScope::All->value),
                $data['location_ids'] ?? [],
            );

            Notification::make()->title(__('actions.membership_saved'))->success()->send();
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }

    private function updateMember(User $record, array $data): void
    {
        $business = Filament::getTenant();
        abort_unless($business instanceof Business && self::canAccess(), 403);
        abort_unless($business->users()->whereKey($record->getKey())->exists(), 404);

        try {
            app(MembershipManager::class)->save(
                $business,
                $record,
                BusinessRole::from($data['role']),
                BranchAccessScope::from($data['access_scope'] ?? BranchAccessScope::All->value),
                $data['location_ids'] ?? [],
            );
            Notification::make()->title(__('actions.membership_saved'))->success()->send();
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }

    private function memberFormState(User $record): array
    {
        $business = Filament::getTenant();
        $locationIds = [];
        $membershipRole = BusinessRole::Receptionist->value;
        $membershipScope = BranchAccessScope::Selected->value;

        if ($business instanceof Business) {
            $membership = DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('user_id', $record->getKey())
                ->first();

            if ($membership) {
                $membershipRole = (string) $membership->role;
                $membershipScope = (string) $membership->access_scope;
            }

            $locationIds = DB::table('business_user_locations')
                ->where('business_id', $business->getKey())
                ->where('user_id', $record->getKey())
                ->pluck('location_id')
                ->map(fn ($id): string => (string) $id)
                ->all();
        }

        return [
            'user_id' => (string) $record->getKey(),
            'role' => $membershipRole,
            'access_scope' => $membershipScope,
            'location_ids' => $locationIds,
        ];
    }

    public function removeMember(string $userId, MembershipManager $memberships): void
    {
        $business = Filament::getTenant();
        abort_unless($business instanceof Business && self::canAccess(), 403);
        abort_unless($business->users()->whereKey($userId)->exists(), 404);

        try {
            $memberships->remove($business, User::query()->findOrFail($userId));
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first())->danger()->send();

            return;
        }

        Notification::make()->title(__('actions.membership_removed'))->success()->send();
    }

    public function editMember(string $userId): void
    {
        $business = Filament::getTenant();
        abort_unless($business instanceof Business && self::canAccess(), 403);
        $member = $business->users()->whereKey($userId)->firstOrFail();
        $locationIds = DB::table('business_user_locations')
            ->where('business_id', $business->getKey())
            ->where('user_id', $userId)
            ->pluck('location_id')->all();

        $this->form->fill([
            'user_id' => (string) $member->getKey(),
            'role' => $member->pivot->role->value,
            'access_scope' => $member->pivot->access_scope->value,
            'location_ids' => $locationIds,
        ]);
    }

    protected function getViewData(): array
    {
        $business = Filament::getTenant();

        return [
            'members' => $business instanceof Business ? $business->users()->orderBy('name')->get() : collect(),
        ];
    }

    private function loadMember(?string $userId): void
    {
        $business = Filament::getTenant();
        if (! $userId || ! $business instanceof Business) {
            return;
        }

        $member = $business->users()->whereKey($userId)->first();
        if (! $member) {
            $this->data['role'] ??= BusinessRole::Receptionist->value;
            $this->data['access_scope'] ??= BranchAccessScope::Selected->value;
            $this->data['location_ids'] = [];

            return;
        }

        $this->editMember((string) $userId);
    }
}
