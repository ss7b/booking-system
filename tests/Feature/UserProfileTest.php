<?php

namespace Tests\Feature;

use App\Domain\Authorization\ManageBusinessMemberships;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\ResetPassword;
use App\Models\Business;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_PASSWORD = 'ExistingFixturePassword123';

    private const NEW_PASSWORD = 'UpdatedFixturePassword42';

    #[DataProvider('panelRoles')]
    public function test_every_panel_role_can_open_the_profile_from_the_user_menu(BusinessRole $role): void
    {
        $business = $this->business();
        $user = $this->member($business, $role);
        $this->actingAs($user, 'web');

        $this->get($this->dashboardUrl($business))
            ->assertOk()
            ->assertSee('My Profile')
            ->assertSee('href="'.$this->profileUrl().'"', false);
        $this->get($this->profileUrl())->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('My Profile')
            ->assertSee($user->name)
            ->assertSee($user->email);
    }

    public static function panelRoles(): array
    {
        return [
            'owner' => [BusinessRole::Owner],
            'manager' => [BusinessRole::Manager],
            'receptionist' => [BusinessRole::Receptionist],
            'staff' => [BusinessRole::Staff],
        ];
    }

    public function test_profile_renders_in_arabic_and_requires_authentication(): void
    {
        $this->get($this->profileUrl())->assertRedirect(route('filament.admin.auth.login'));
        $user = $this->member($this->business(), BusinessRole::Owner);
        $user->forceFill(['preferred_locale' => 'ar'])->save();

        $this->actingAs($user, 'web')->get($this->profileUrl())
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('الملف الشخصي');
    }

    public function test_name_updates_the_same_owner_across_tenants_and_shows_in_the_user_menu(): void
    {
        $firstBusiness = $this->business();
        $secondBusiness = $this->business();
        $owner = $this->member($firstBusiness, BusinessRole::Owner);
        app(ManageBusinessMemberships::class)->save($secondBusiness, $owner, BusinessRole::Owner, BranchAccessScope::All, []);
        $anotherOwner = $this->member($secondBusiness, BusinessRole::Owner);
        $linkedStaff = $firstBusiness->staff()->create(['name' => 'Distinct staff display name', 'user_id' => $owner->id, 'status' => 'active']);
        $this->actingAs($owner, 'web');

        $this->get($this->dashboardUrl($firstBusiness))->assertOk()->assertSee($owner->name);
        $this->get($this->profileUrl().'?user_id='.$anotherOwner->id)->assertOk()
            ->assertSee($owner->email)
            ->assertDontSee($anotherOwner->email);

        $this->profile()->set('data.name', 'Updated account display name')->call('save')
            ->assertHasNoErrors()
            ->assertNotified(__('profile.notifications.name_updated', locale: 'en'));

        $this->assertSame('Updated account display name', $owner->fresh()->name);
        $this->assertSame('Distinct staff display name', $linkedStaff->fresh()->name);
        $this->assertSame($anotherOwner->name, $anotherOwner->fresh()->name);
        $session = $this->sessionFor($owner);
        foreach ([$firstBusiness, $secondBusiness] as $business) {
            $this->useSession($session);
            $this->get($this->dashboardUrl($business))->assertOk()->assertSee('Updated account display name');
            $this->assertDatabaseHas('business_user', ['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner', 'access_scope' => 'all']);
        }
    }

    public function test_name_validation_and_extra_livewire_fields_preserve_other_accounts_and_memberships(): void
    {
        $business = $this->business();
        $otherBusiness = $this->business();
        $account = $this->member($business, BusinessRole::Staff);
        $otherAccount = $this->member($otherBusiness, BusinessRole::Staff);
        $otherAccountBefore = $otherAccount->fresh()->getAttributes();
        $branch = $business->locations()->create(['name' => 'Assigned', 'slug' => 'assigned', 'is_active' => true]);
        app(ManageBusinessMemberships::class)->save($business, $account, BusinessRole::Staff, BranchAccessScope::Selected, [$branch->id]);
        $staff = $business->staff()->create(['name' => 'Staff fixture', 'user_id' => $account->id, 'status' => 'active']);
        $this->actingAs($account, 'web');

        $this->profile()->set('data.name', '')->call('save')->assertHasErrors(['data.name' => 'required']);
        $this->profile()->set('data.name', str_repeat('أ', 256))->call('save')->assertHasErrors(['data.name' => 'max']);
        $this->assertSame($account->name, $account->fresh()->name);

        $snapshot = $this->snapshot($this->get($this->profileUrl())->assertOk());
        $this->update($snapshot, 'save', [
            'data.name' => 'Current account only',
            'data.id' => $otherAccount->id,
            'data.user_id' => $otherAccount->id,
            'data.business_id' => $otherBusiness->id,
            'data.role' => 'owner',
            'data.access_scope' => 'all',
            'data.location_ids' => [],
            'data.staff_id' => null,
            'data.email' => 'other-address@example.test',
            'data.password' => 'InjectedFixturePassword123',
        ])->assertOk();

        $this->assertSame('Current account only', $account->fresh()->name);
        $this->assertSame($account->email, $account->fresh()->email);
        $this->assertSame($account->password, $account->fresh()->password);
        $this->assertSame($otherAccountBefore, $otherAccount->fresh()->getAttributes());
        $this->assertDatabaseHas('business_user', ['user_id' => $account->id, 'business_id' => $business->id, 'role' => 'staff', 'access_scope' => 'selected']);
        $this->assertDatabaseHas('business_user_locations', ['user_id' => $account->id, 'location_id' => $branch->id]);
        $this->assertDatabaseHas('staff', ['id' => $staff->id, 'user_id' => $account->id, 'name' => 'Staff fixture']);
    }

    public function test_livewire_rejects_updates_without_a_csrf_token(): void
    {
        $user = $this->member($this->business(), BusinessRole::Owner);
        $this->actingAs($user, 'web');
        $snapshot = $this->snapshot($this->get($this->profileUrl())->assertOk());

        $this->app->instance('env', 'local');
        try {
            $this->update($snapshot, 'save', ['data.name' => 'Unverified CSRF name'])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }

        $this->assertSame($user->name, $user->fresh()->name);
    }

    #[DataProvider('newlyUnavailableAccounts')]
    public function test_stale_profile_snapshots_cannot_update_an_unavailable_account(string $change): void
    {
        $business = $this->business();
        $user = $this->member($business, BusinessRole::Receptionist);
        $session = $this->sessionFor($user);
        $this->useSession($session);
        $snapshot = $this->snapshot($this->get($this->profileUrl())->assertOk());

        match ($change) {
            'disabled account' => $user->forceFill(['is_active' => false])->save(),
            'disabled business' => $business->update(['is_active' => false]),
            'removed membership' => app(ManageBusinessMemberships::class)->remove($business, $user),
        };

        $this->useSession($session);
        $this->update($snapshot, 'save', ['data.name' => 'Stale snapshot name'])->assertForbidden();
        $this->get($this->profileUrl())->assertForbidden();
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public static function newlyUnavailableAccounts(): array
    {
        return [
            'disabled account' => ['disabled account'],
            'disabled business' => ['disabled business'],
            'removed membership' => ['removed membership'],
        ];
    }

    public function test_password_change_requires_current_password_strength_and_confirmation(): void
    {
        $user = $this->member($this->business(), BusinessRole::Owner);
        $this->actingAs($user, 'web');

        $this->profile()->set('passwordData', $this->passwordData(current: 'WrongPassword123'))->call('updatePassword')
            ->assertHasErrors('passwordData.current_password');
        $this->profile()->set('passwordData', $this->passwordData(password: 'weak'))->call('updatePassword')
            ->assertHasErrors('passwordData.password');
        $this->profile()->set('passwordData', $this->passwordData(confirmation: 'DifferentFixturePassword456'))->call('updatePassword')
            ->assertHasErrors('passwordData.password_confirmation');

        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_password_change_hashes_the_new_password_resets_inputs_and_changes_login_credentials(): void
    {
        $business = $this->business();
        $user = $this->member($business, BusinessRole::Manager);
        $otherUser = $this->member($business, BusinessRole::Manager);
        $this->actingAs($user, 'web');

        $this->profile()->set('passwordData', [...$this->passwordData(), 'user_id' => $otherUser->id])->call('updatePassword')
            ->assertHasNoErrors()
            ->assertSet('passwordData.current_password', null)
            ->assertSet('passwordData.password', null)
            ->assertSet('passwordData.password_confirmation', null)
            ->assertNotified(__('profile.notifications.password_updated', locale: 'en'));

        $updated = $user->fresh();
        $this->assertNotSame(self::NEW_PASSWORD, $updated->password);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $updated->password));
        $this->assertSame($otherUser->password, $otherUser->fresh()->password);
        $this->assertDatabaseHas('business_user', ['user_id' => $user->id, 'business_id' => $business->id, 'role' => 'manager', 'access_scope' => 'selected']);

        Auth::guard('web')->logout();
        $this->assertFalse(Auth::guard('web')->attempt(['email' => $updated->email, 'password' => self::CURRENT_PASSWORD]));
        $this->assertTrue(Auth::guard('web')->attempt(['email' => $updated->email, 'password' => self::NEW_PASSWORD]));
        $this->assertAuthenticatedAs($updated, 'web');
    }

    public function test_password_change_is_rate_limited_for_repeated_incorrect_current_passwords(): void
    {
        $user = $this->member($this->business(), BusinessRole::Owner);
        $originalHash = $user->password;
        $this->actingAs($user, 'web');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->profile()->set('passwordData', $this->passwordData(current: 'WrongPassword123'))->call('updatePassword')
                ->assertHasErrors('passwordData.current_password');
        }
        $this->profile()->set('passwordData', $this->passwordData())->call('updatePassword')->assertHasErrors();
        $this->assertSame($originalHash, $user->fresh()->password);

        $this->travel(61)->seconds();
        $this->profile()->set('passwordData', $this->passwordData())->call('updatePassword')->assertHasNoErrors();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_password_change_rotates_the_current_session_and_rejects_other_sessions(): void
    {
        $user = $this->member($this->business(), BusinessRole::Owner);
        $currentSession = $this->sessionFor($user);
        $otherSession = $this->sessionFor($user);

        $this->useSession($otherSession);
        $otherSnapshot = $this->snapshot($this->get($this->profileUrl())->assertOk());
        $this->useSession($currentSession);
        $currentSnapshot = $this->snapshot($this->get($this->profileUrl())->assertOk());

        $changed = $this->update($currentSnapshot, 'updatePassword', ['passwordData' => $this->passwordData()])->assertOk();
        $rotated = $changed->getCookie(config('session.cookie'))->getValue();
        $this->assertNotSame($currentSession, $rotated);
        $this->useSession($rotated);
        $this->get($this->profileUrl())->assertOk()->assertSee($user->email);

        $this->useSession($otherSession);
        $this->update($otherSnapshot, 'save', ['data.name' => 'Stale browser name'])->assertUnauthorized();
        $this->get($this->profileUrl())->assertRedirect(route('filament.admin.auth.login'));
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_recovery_rejects_both_old_panel_sessions_and_the_old_remember_cookie(): void
    {
        config([
            'account-security.mail_enabled' => true,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.url' => null,
            'mail.from.address' => 'security@example.test',
        ]);
        Mail::fake();
        $user = $this->member($this->business(), BusinessRole::Owner);
        $guard = Auth::guard('web');
        $recaller = implode('|', [$user->id, $user->getRememberToken(), $guard->hashPasswordForCookie($user->getAuthPassword())]);
        $recallerName = $guard->getRecallerName();
        $sessions = [$this->sessionFor($user), $this->sessionFor($user)];
        $this->useSession($sessions[0]);
        $snapshot = $this->snapshot($this->get($this->profileUrl())->assertOk());
        $this->useSession(Str::random(40));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => Password::broker()->createToken($user)])
            ->set('password', self::NEW_PASSWORD)->set('passwordConfirmation', self::NEW_PASSWORD)
            ->call('resetPassword')->assertRedirect(route('filament.admin.auth.login'));
        $this->assertGuest('web');

        $this->useSession($sessions[0]);
        $this->update($snapshot, 'save', ['data.name' => 'Stale browser name'])->assertUnauthorized();
        $this->useSession($sessions[1]);
        $this->get($this->profileUrl())->assertRedirect(route('filament.admin.auth.login'));
        $this->useSession(Str::random(40));
        $this->withCookie($recallerName, $recaller)->get($this->profileUrl())
            ->assertRedirect(route('filament.admin.auth.login'));
        $this->assertSame($user->name, $user->fresh()->name);
    }

    private function business(): Business
    {
        return Business::query()->create([
            'name' => 'Profile test business', 'slug' => 'profile-'.Str::lower(Str::random(12)),
            'timezone' => 'Asia/Riyadh', 'default_currency' => 'SAR', 'is_active' => true,
        ]);
    }

    private function member(Business $business, BusinessRole $role): User
    {
        $user = User::factory()->create(['password' => self::CURRENT_PASSWORD]);
        $user->forceFill(['preferred_locale' => 'en'])->save();
        app(ManageBusinessMemberships::class)->save($business, $user, $role, BranchAccessScope::Selected, []);

        return $user;
    }

    private function profile(): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(EditProfile::class);
    }

    private function profileUrl(): string
    {
        return route('filament.admin.auth.profile');
    }

    private function dashboardUrl(Business $business): string
    {
        return route('filament.admin.pages.dashboard', ['tenant' => $business]);
    }

    private function sessionFor(User $user): string
    {
        $guard = Auth::guard('web');
        $id = Str::random(40);
        session()->getHandler()->write($id, serialize([
            $guard->getName() => $user->getAuthIdentifier(),
            'password_hash_web' => $guard->hashPasswordForCookie($user->getAuthPassword()),
        ]));

        return $id;
    }

    private function useSession(string $id): void
    {
        Auth::forgetGuards();
        session()->flush();
        $this->withCredentials()->withCookie(config('session.cookie'), $id);
    }

    private function snapshot(TestResponse $response): string
    {
        preg_match_all('/\bwire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES, 'UTF-8');
            $component = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['name'];
            if (str_ends_with($component, 'pages.auth.edit-profile')) {
                return $snapshot;
            }
        }

        $this->fail('The profile page did not render its Livewire snapshot.');
    }

    private function update(string $snapshot, string $method, array $updates): TestResponse
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }

    private function passwordData(string $current = self::CURRENT_PASSWORD, string $password = self::NEW_PASSWORD, ?string $confirmation = null): array
    {
        return ['current_password' => $current, 'password' => $password, 'password_confirmation' => $confirmation ?? $password];
    }
}
