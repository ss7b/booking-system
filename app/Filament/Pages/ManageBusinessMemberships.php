<?php

namespace App\Filament\Pages;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Authorization\ManageBusinessMemberships as MembershipManager;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageBusinessMemberships extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Business Setup';

    protected static ?string $navigationLabel = 'Memberships';

    protected static string $view = 'filament.pages.manage-business-memberships';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        return $business instanceof Business && $user
            && app(BusinessAccess::class)->canManageMemberships($user, $business);
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
                Checkbox::make('create_new_account')
                    ->label('Create new user account')
                    ->live(),
                TextInput::make('new_name')
                    ->label('New user name')
                    ->required(fn (Get $get): bool => (bool) $get('create_new_account'))
                    ->visible(fn (Get $get): bool => (bool) $get('create_new_account')),
                TextInput::make('new_email')
                    ->label('New user email')
                    ->email()
                    ->required(fn (Get $get): bool => (bool) $get('create_new_account'))
                    ->visible(fn (Get $get): bool => (bool) $get('create_new_account')),
                TextInput::make('new_password')
                    ->label('New user password')
                    ->password()
                    ->required(fn (Get $get): bool => (bool) $get('create_new_account'))
                    ->minLength(12)
                    ->visible(fn (Get $get): bool => (bool) $get('create_new_account')),
                Select::make('user_id')
                    ->label('Existing user account')
                    ->options(fn () => User::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name.' ('.$user->email.')']))
                    ->searchable()->preload()->live()
                    ->required(fn (Get $get): bool => ! (bool) $get('create_new_account'))
                    ->disabled(fn (Get $get): bool => (bool) $get('create_new_account'))
                    ->afterStateUpdated(fn ($state) => $this->loadMember($state)),
                Select::make('role')
                    ->options(collect(BusinessRole::cases())->mapWithKeys(fn (BusinessRole $role): array => [$role->value => str($role->value)->headline()->toString()]))
                    ->required()->live(),
                Radio::make('access_scope')
                    ->options(['all' => 'All branches', 'selected' => 'Selected branches'])
                    ->required()
                    ->disabled(fn (Get $get): bool => $get('role') === BusinessRole::Owner->value)
                    ->visible(fn (Get $get): bool => $get('role') !== BusinessRole::Owner->value),
                CheckboxList::make('location_ids')
                    ->label('Assigned branches')
                    ->options(fn (): array => Filament::getTenant() instanceof Business
                        ? Filament::getTenant()->locations()->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => $get('access_scope') === BranchAccessScope::Selected->value && $get('role') !== BusinessRole::Owner->value),
            ])
            ->statePath('data');
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

        abort_unless(! empty($userId), 422, 'Select an existing user or create a new one.');

        $memberships->save(
            $business,
            User::query()->findOrFail($userId),
            BusinessRole::from($data['role']),
            BranchAccessScope::from($data['access_scope'] ?? BranchAccessScope::All->value),
            $data['location_ids'] ?? [],
        );

        Notification::make()->title('Membership saved')->success()->send();
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

        Notification::make()->title('Membership removed')->success()->send();
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
