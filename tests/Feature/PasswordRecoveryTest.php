<?php

namespace Tests\Feature;

use App\Domain\Account\ChangeAccountEmail;
use App\Domain\Authorization\ManageBusinessMemberships;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Mail\AccountPasswordMail;
use App\Mail\VerifyEmailChange;
use App\Models\Business;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

use function Illuminate\Support\defer;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'OriginalProfile12!';

    private const NEW_PASSWORD = 'RecoveredProfile34!';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'account-security.mail_enabled' => true,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.url' => null,
            'mail.from.address' => 'security@example.test',
        ]);
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withSession(['filament_locale' => 'en']);
    }

    public function test_login_link_and_recovery_routes_follow_system_mail_readiness(): void
    {
        $account = $this->account();
        $this->get(route('filament.admin.auth.login'))->assertOk()->assertSee($this->requestUrl(), false);
        $this->get($this->requestUrl())->assertOk()->assertSee('dir="ltr"', false);
        $stale = $this->requestForm($account->email);
        $token = Password::broker()->createToken($account);
        $resetForm = $this->resetForm($account, $token);

        config(['account-security.mail_enabled' => false]);

        $this->get(route('filament.admin.auth.login'))->assertOk()->assertDontSee($this->requestUrl(), false);
        $this->get($this->requestUrl())->assertStatus(503);
        $this->get($this->resetUrl($account, $token))->assertStatus(503);
        $stale->call('request')->assertStatus(503);
        $resetForm->call('resetPassword')->assertStatus(503);
        $this->assertTrue(Password::broker()->tokenExists($account, $token));
        Mail::assertNothingSent();
    }

    public function test_requests_are_uniform_and_only_eligible_accounts_receive_canonical_localized_system_mail(): void
    {
        $account = $this->account();
        $account->forceFill(['preferred_locale' => 'ar'])->save();
        $inactive = $this->account();
        $inactive->forceFill(['is_active' => false])->save();
        config(['app.url' => 'https://accounts.example.test']);

        $this->requestForm('bad-email')->call('request')->assertHasErrors(['data.email' => 'email']);
        foreach ([$account->email, $inactive->email, 'unknown@example.test'] as $email) {
            $this->requestForm($email)->call('request')
                ->assertHasNoErrors()->assertNotified(__('password_reset.requested', locale: 'en'));
        }
        defer()->invoke();

        Mail::assertSent(AccountPasswordMail::class, 1);
        $mail = Mail::sent(AccountPasswordMail::class)->sole();
        $this->assertTrue($mail->hasTo($account->email));
        $this->assertSame('smtp', $mail->mailer);
        $this->assertSame('ar', $mail->locale);
        $this->assertStringStartsWith('https://accounts.example.test/admin/password-reset/reset?', $mail->resetUrl);
        $mail->assertSeeInHtml('30 دقيقة')->assertSeeInHtml('إعادة تعيين كلمة المرور');
        parse_str(parse_url($mail->resetUrl, PHP_URL_QUERY), $link);
        $row = DB::table('password_reset_tokens')->where('email', $account->email)->first();
        $this->assertNotSame($link['token'], $row->token);
        $this->assertTrue(Hash::check($link['token'], $row->token));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $inactive->email]);
        $this->withSession(['filament_locale' => 'ar'])->get($this->requestUrl())->assertOk()->assertSee('dir="rtl"', false)
            ->assertSee('إرسال رابط الاستعادة');
    }

    public function test_new_requests_replace_tokens_with_broker_and_per_address_throttling(): void
    {
        $account = $this->account();
        $this->requestForm($account->email)->call('request');
        defer()->invoke();
        $first = $this->lastToken();
        $this->requestForm(strtoupper($account->email))->call('request')->assertNotified(__('password_reset.requested', locale: 'en'));
        defer()->invoke();
        Mail::assertSent(AccountPasswordMail::class, 1);

        $this->travel(61)->seconds();
        $this->requestForm($account->email)->call('request');
        defer()->invoke();
        $current = $this->lastToken();
        $this->assertNotSame($first, $current);
        $this->assertFalse(Password::broker()->tokenExists($account, $first));

        $this->travel(61)->seconds();
        $this->requestForm($account->email)->call('request')->assertNotified(__('password_reset.requested', locale: 'en'));
        defer()->invoke();
        Mail::assertSent(AccountPasswordMail::class, 2);
        $this->assertTrue(Password::broker()->tokenExists($account, $current));
    }

    public function test_request_rate_limit_applies_across_addresses_at_the_same_ip(): void
    {
        $account = $this->account();
        for ($i = 0; $i < 10; $i++) {
            $this->requestForm('unregistered-'.$i.'@example.test')->call('request');
        }

        $this->requestForm($account->email)->call('request')->assertNotified(__('password_reset.requested', locale: 'en'));
        defer()->invoke();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $account->email]);
        Mail::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_every_role_can_reset_without_changing_membership_or_staff_data(BusinessRole $role): void
    {
        $account = $this->account($role);
        $business = $account->businesses()->firstOrFail();
        if ($role === BusinessRole::Owner) {
            app(ManageBusinessMemberships::class)->save($this->business(), $account, $role, BranchAccessScope::All, []);
        }
        $staff = $business->staff()->create(['name' => 'Operational staff name', 'status' => 'active']);
        $staff->forceFill(['user_id' => $account->id])->save();
        $memberships = $account->businesses()->orderBy('businesses.id')->get()->map->pivot->toArray();
        $locations = $account->assignedLocations()->orderBy('locations.id')->get()->map->pivot->toArray();
        $token = Password::broker()->createToken($account);
        app(ChangeAccountEmail::class)->request($account, 'pending-address@example.test', self::OLD_PASSWORD);

        $this->submit($this->resetForm($account, $token))->assertHasNoErrors()
            ->assertRedirect(route('filament.admin.auth.login'));
        defer()->invoke();
        $this->assertGuest('web');
        $this->assertFalse(Password::broker()->tokenExists($account, $token));
        $this->assertDatabaseMissing('pending_email_changes', ['user_id' => $account->id]);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $account->fresh()->getAuthPassword()));
        $this->assertFalse(Auth::guard('web')->validate(['email' => $account->email, 'password' => self::OLD_PASSWORD]));
        $this->assertTrue(Auth::guard('web')->validate(['email' => $account->email, 'password' => self::NEW_PASSWORD]));
        $this->assertNotSame($account->remember_token, $account->fresh()->remember_token);
        $this->assertSame($memberships, $account->businesses()->orderBy('businesses.id')->get()->map->pivot->toArray());
        $this->assertSame($locations, $account->assignedLocations()->orderBy('locations.id')->get()->map->pivot->toArray());
        $this->assertSame('Operational staff name', $staff->fresh()->name);
        $this->assertSame($account->id, $staff->fresh()->user_id);
        Mail::assertSent(AccountPasswordMail::class, fn (AccountPasswordMail $mail): bool => $mail->hasTo($account->email)
            && $mail->mailer === 'smtp' && $mail->resetUrl === null && $mail->changedAt !== null);

        $this->submit($this->resetForm($account, $token))->assertNotified(__('password_reset.invalid_link', locale: 'en'));
    }

    public static function roles(): array
    {
        return array_map(static fn (BusinessRole $role): array => [$role], BusinessRole::cases());
    }

    public function test_signed_links_and_locked_email_cannot_be_forged_and_invalid_or_expired_tokens_fail(): void
    {
        $account = $this->account();
        $other = $this->account();
        $token = Password::broker()->createToken($account);
        $this->get($this->resetUrl($account, $token))->assertOk();
        $this->get($this->resetUrl($account, $token).'&email='.urlencode($other->email))->assertForbidden();

        $this->submit($this->resetForm($other, $token))->assertNotified(__('password_reset.invalid_link', locale: 'en'));
        $this->submit($this->resetForm($account, str_repeat('a', 64)))->assertNotified(__('password_reset.invalid_link', locale: 'en'));
        $this->travel(1801)->seconds();
        $this->submit($this->resetForm($account, $token))->assertNotified(__('password_reset.invalid_link', locale: 'en'));
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $account->fresh()->getAuthPassword()));

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->resetForm($account, $token)->set('email', $other->email);
    }

    public function test_reset_validates_policy_and_throttles_invalid_token_attempts(): void
    {
        $account = $this->account();
        $token = Password::broker()->createToken($account);
        $this->submit($this->resetForm($account, $token), 'short')->assertHasErrors('password');
        $this->submit($this->resetForm($account, $token), self::NEW_PASSWORD, 'Different123')->assertHasErrors('password');
        $this->submit($this->resetForm($account, $token), self::OLD_PASSWORD)->assertHasErrors('password');
        for ($i = 0; $i < 4; $i++) {
            $this->submit($this->resetForm($account, str_repeat('a', 64)))->assertNotified(__('password_reset.invalid_link', locale: 'en'));
        }
        $this->submit($this->resetForm($account, $token))->assertNotified(__('password_reset.throttled', locale: 'en'));
        $this->assertTrue(Password::broker()->tokenExists($account, $token));
    }

    public function test_delivery_failures_are_generic_and_do_not_undo_a_successful_reset(): void
    {
        $account = $this->account();
        Log::spy();
        Mail::shouldReceive('mailer')->with('smtp')->twice()->andThrow(new RuntimeException('sensitive transport diagnostics'));

        $this->requestForm($account->email)->call('request')->assertNotified(__('password_reset.requested', locale: 'en'));
        defer()->invoke();
        $token = Password::broker()->createToken($account);
        $this->submit($this->resetForm($account, $token))->assertRedirect(route('filament.admin.auth.login'));
        defer()->invoke();

        Log::shouldHaveReceived('warning')->with('Account password email delivery failed.', ['exception' => RuntimeException::class])->twice();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $account->fresh()->getAuthPassword()));
        $this->assertFalse(Password::broker()->tokenExists($account, $token));
    }

    public function test_profile_password_and_verified_email_changes_consume_broker_tokens(): void
    {
        $account = $this->account();
        $token = Password::broker()->createToken($account);
        $this->actingAs($account, 'web');
        Livewire::test(EditProfile::class)->set('passwordData', [
            'current_password' => self::OLD_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->call('updatePassword')->assertHasNoErrors();
        $this->assertFalse(Password::broker()->tokenExists($account, $token));

        $account->refresh();
        $token = Password::broker()->createToken($account);
        app(ChangeAccountEmail::class)->request($account, 'new-login@example.test', self::NEW_PASSWORD);
        $oldEmail = $account->email;
        $this->get(Mail::sent(VerifyEmailChange::class)->sole()->verificationUrl)->assertRedirect(route('filament.admin.auth.profile'));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        Auth::guard('web')->logout();
        $this->submit($this->resetForm($account, $token))->assertNotified(__('password_reset.invalid_link', locale: 'en'));
    }

    private function account(BusinessRole $role = BusinessRole::Owner): User
    {
        $business = $this->business();
        $user = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $branch = $business->locations()->create([
            'name' => 'Recovery branch', 'slug' => 'recovery-branch', 'timezone' => 'Asia/Riyadh', 'is_active' => true,
        ]);
        app(ManageBusinessMemberships::class)->save(
            $business, $user, $role, BranchAccessScope::Selected, [(string) $branch->getKey()],
        );

        return $user;
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Password recovery test', 'slug' => 'recovery-'.Str::lower(Str::random(12)),
            'timezone' => 'Asia/Riyadh', 'default_currency' => 'SAR', 'is_active' => true,
        ]);
    }

    private function requestUrl(): string
    {
        return route('filament.admin.auth.password-reset.request');
    }

    private function resetUrl(User $account, string $token): string
    {
        return URL::signedRoute('filament.admin.auth.password-reset.reset', ['email' => $account->email, 'token' => $token]);
    }

    private function requestForm(string $email): Testable
    {
        return Livewire::test(RequestPasswordReset::class)->set('data.email', $email);
    }

    private function resetForm(User $account, string $token): Testable
    {
        return Livewire::test(ResetPassword::class, ['email' => $account->email, 'token' => $token]);
    }

    private function submit(Testable $form, string $password = self::NEW_PASSWORD, ?string $confirmation = null): Testable
    {
        return $form->set('password', $password)->set('passwordConfirmation', $confirmation ?? $password)->call('resetPassword');
    }

    private function lastToken(): string
    {
        parse_str(parse_url(Mail::sent(AccountPasswordMail::class)->last()->resetUrl, PHP_URL_QUERY), $parts);

        return $parts['token'];
    }
}
