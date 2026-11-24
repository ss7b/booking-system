<?php

namespace App\Filament\Pages\Auth;

use App\Domain\Account\ChangeAccountEmail;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * @property Form $emailForm
 * @property Form $passwordForm
 */
final class EditProfile extends BaseEditProfile
{
    protected static string $view = 'filament.pages.auth.edit-profile';

    protected static ?string $slug = 'account/profile';

    public array $emailData = [];

    public array $passwordData = [];

    public static function getLabel(): string
    {
        return __('profile.title');
    }

    public function getMaxWidth(): MaxWidth
    {
        return MaxWidth::ThreeExtraLarge;
    }

    public function getUser(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function fillForm(): void
    {
        $this->form->fill(['name' => $this->authorizedAccount()->name]);
        $this->emailForm->fill();
        $this->passwordForm->fill();
    }

    protected function getForms(): array
    {
        return [
            'form' => $this->makeForm()
                ->schema([
                    TextInput::make('name')
                        ->label(__('profile.fields.name'))
                        ->required()
                        ->rule('string')
                        ->rule('regex:/[^\p{Z}\p{C}\s]/u')
                        ->maxLength(255)
                        ->autocomplete('name')
                        ->dehydrateStateUsing(fn (string $state): string => Str::trim($state)),
                ])
                ->statePath('data'),
            'emailForm' => $this->makeForm()
                ->schema([
                    TextInput::make('email')
                        ->label(__('profile.fields.new_email'))
                        ->required()
                        ->rule('string')
                        ->email()
                        ->maxLength(255)
                        ->autocomplete('email')
                        ->extraInputAttributes(['dir' => 'ltr']),
                    $this->currentPasswordInput(),
                ])
                ->statePath('emailData'),
            'passwordForm' => $this->makeForm()
                ->schema([
                    $this->currentPasswordInput(),
                    TextInput::make('password')
                        ->label(__('profile.fields.new_password'))
                        ->password()
                        ->required()
                        ->rule('string')
                        ->rule(Password::min(12)->letters()->numbers())
                        ->validationMessages([
                            'password.letters' => __('profile.errors.password_letters'),
                            'password.numbers' => __('profile.errors.password_numbers'),
                        ])
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                                $fail(__('profile.errors.password_format'));
                            }
                        })
                        ->autocomplete('new-password'),
                    TextInput::make('password_confirmation')
                        ->label(__('profile.fields.confirm_password'))
                        ->password()
                        ->required()
                        ->rule('string')
                        ->same('password')
                        ->maxLength(72)
                        ->autocomplete('new-password'),
                ])
                ->statePath('passwordData'),
        ];
    }

    public function save(): void
    {
        $user = $this->authorizedAccount();
        $data = $this->form->getState();
        $user->update(['name' => $data['name']]);

        Notification::make()->success()->title(__('profile.notifications.name_updated'))->send();
        $this->redirect(Filament::getProfileUrl());
    }

    public function requestEmailChange(ChangeAccountEmail $emailChange): void
    {
        try {
            $user = $this->authorizedAccount();
            $data = $this->emailForm->getState();

            try {
                $emailChange->request($user, $data['email'], $data['current_password']);
            } catch (ValidationException $exception) {
                $messages = [];
                foreach ($exception->errors() as $field => $errors) {
                    $messages['emailData.'.$field] = $errors;
                }

                throw ValidationException::withMessages($messages);
            }

            $this->emailForm->fill();
            Notification::make()->success()->title(__('profile.notifications.email_sent'))->send();
        } finally {
            $this->emailData['current_password'] = null;
        }
    }

    public function updatePassword(): void
    {
        try {
            $user = $this->authorizedAccount();
            $throttleKey = 'account-password-update:'.$user->getKey();
            if (RateLimiter::increment($throttleKey, 60) > 5) {
                throw ValidationException::withMessages([
                    'passwordData.current_password' => __('profile.errors.password_throttled', [
                        'seconds' => RateLimiter::availableIn($throttleKey),
                    ]),
                ]);
            }

            $data = $this->passwordForm->getState();
            $user = DB::transaction(function () use ($user, $data): User {
                $account = User::query()->lockForUpdate()->findOrFail($user->getKey());
                abort_unless($account->canAccessPanel(Filament::getPanel('admin')), 403);

                if (! Hash::check($data['current_password'], $account->getAuthPassword())) {
                    throw ValidationException::withMessages([
                        'passwordData.current_password' => __('profile.errors.invalid_current_password'),
                    ]);
                }

                if (Hash::check($data['password'], $account->getAuthPassword())) {
                    throw ValidationException::withMessages([
                        'passwordData.password' => __('profile.errors.password_reused'),
                    ]);
                }

                $account->password = Hash::make($data['password']);
                $account->setRememberToken(Str::random(60));
                $account->save();

                return $account;
            });

            /** @var SessionGuard $guard */
            $guard = Filament::auth();
            $guard->login($user, request()->cookies->has($guard->getRecallerName()));
            RateLimiter::clear($throttleKey);

            Notification::make()->success()->title(__('profile.notifications.password_updated'))->send();
            $this->redirect(Filament::getProfileUrl());
        } finally {
            $this->passwordData = [];
        }
    }

    private function authorizedAccount(): User
    {
        $account = $this->getUser()->fresh();
        abort_unless($account instanceof User && $account->canAccessPanel(Filament::getPanel('admin')), 403);

        return $account;
    }

    private function currentPasswordInput(): TextInput
    {
        return TextInput::make('current_password')
            ->label(__('profile.fields.current_password'))
            ->password()
            ->required()
            ->rule('string')
            ->maxLength(4096)
            ->autocomplete('current-password');
    }
}
