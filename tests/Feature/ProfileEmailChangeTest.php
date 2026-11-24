<?php

namespace Tests\Feature;

use App\Domain\Account\ChangeAccountEmail;
use App\Domain\Authorization\ManageBusinessMemberships;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use App\Filament\Pages\Auth\EditProfile;
use App\Mail\EmailChangeCompleted;
use App\Mail\VerifyEmailChange;
use App\Models\Business;
use App\Models\BusinessCustomerNotificationSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'ProfileEmailFixture123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'account-security.mail_enabled' => true,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.invalid.test',
            'mail.from.address' => 'security@example.test',
        ]);
        Mail::fake();
    }

    public function test_verified_email_replaces_login_identity_and_alerts_the_old_address_using_application_mail(): void
    {
        [$business, $owner] = $this->context();
        [$otherBusiness, $otherOwner] = $this->context();
        app(ManageBusinessMemberships::class)->save($otherBusiness, $owner, BusinessRole::Owner, BranchAccessScope::All, []);

        $settings = BusinessCustomerNotificationSettings::defaults();
        $settings['channels']['email'] = [
            'enabled' => true,
            'credentials' => ['host' => 'tenant-smtp.invalid.test', 'port' => 587, 'from_address' => 'bookings@example.test'],
        ];
        BusinessCustomerNotificationSettings::query()->create(['business_id' => $business->id, 'settings' => $settings]);

        $verifiedBefore = $owner->email_verified_at;
        $oldEmail = $owner->email;
        $newEmail = 'new-account-address@example.test';
        $this->actingAs($owner, 'web');
        $this->get(route('filament.admin.pages.dashboard', ['tenant' => $business]))->assertOk();

        $this->requestThroughProfile($newEmail)
            ->assertHasNoErrors()
            ->assertNotified(__('profile.notifications.email_sent', locale: 'en'))
            ->assertSee($newEmail)
            ->assertSet('emailData.current_password', null);

        $this->assertSame($oldEmail, $owner->fresh()->email);
        $this->assertTrue($owner->fresh()->email_verified_at->equalTo($verifiedBefore));
        $this->assertTrue(Auth::guard('web')->validate(['email' => $oldEmail, 'password' => self::PASSWORD]));
        $this->assertFalse(Auth::guard('web')->validate(['email' => $newEmail, 'password' => self::PASSWORD]));
        Mail::assertNotSent(EmailChangeCompleted::class);

        $link = $this->lastLink($newEmail);
        $token = basename(parse_url($link, PHP_URL_PATH));
        $this->assertDatabaseHas('pending_email_changes', ['user_id' => $owner->id, 'email' => $newEmail, 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('pending_email_changes', ['token_hash' => $token]);
        $this->head($link)->assertMethodNotAllowed();
        $this->get($link, ['Purpose' => 'prefetch'])->assertMethodNotAllowed();
        $this->assertSame($oldEmail, $owner->fresh()->email);
        $this->get($link)->assertRedirect($this->profileUrl());
        $this->assertSame($newEmail, $owner->fresh()->email);
        $this->assertNotNull($owner->fresh()->email_verified_at);
        $this->assertNull(app(ChangeAccountEmail::class)->pendingFor($owner->fresh()));
        $this->actingAs($owner->fresh(), 'web')->get($this->profileUrl())->assertOk()
            ->assertSee($newEmail)
            ->assertDontSee('Email Change Pending');
        $this->assertSame($otherOwner->email, $otherOwner->fresh()->email);
        $this->assertFalse(Auth::guard('web')->validate(['email' => $oldEmail, 'password' => self::PASSWORD]));
        $this->assertTrue(Auth::guard('web')->validate(['email' => $newEmail, 'password' => self::PASSWORD]));
        Mail::assertSent(EmailChangeCompleted::class, fn (EmailChangeCompleted $mail): bool => $mail->hasTo($oldEmail) && $mail->mailer === 'smtp');

        foreach ([$business, $otherBusiness] as $membership) {
            $this->assertDatabaseHas('business_user', ['user_id' => $owner->id, 'business_id' => $membership->id, 'role' => 'owner', 'access_scope' => 'all']);
        }
        $this->get($link)->assertForbidden();
        Mail::assertSent(EmailChangeCompleted::class, 1);
    }

    public function test_email_request_rejects_invalid_duplicate_unchanged_addresses_and_wrong_password(): void
    {
        [, $owner] = $this->context();
        $other = User::factory()->create();
        $this->actingAs($owner, 'web');

        $this->requestThroughProfile('invalid-address')->assertHasErrors('emailData.email');
        $this->requestThroughProfile($other->email)->assertHasErrors('emailData.email');
        $this->requestThroughProfile(strtoupper($owner->email))->assertHasErrors('emailData.email');
        $this->requestThroughProfile('pw-must-match@example.test', 'WrongPassword123')->assertHasErrors('emailData.current_password');
        $this->assertNull(app(ChangeAccountEmail::class)->pendingFor($owner));
        $this->assertSame($owner->email, $owner->fresh()->email);
        Mail::assertNothingSent();
    }

    public function test_email_service_requires_system_mail_even_with_customer_smtp_settings(): void
    {
        [$business, $owner] = $this->context();
        $settings = BusinessCustomerNotificationSettings::defaults();
        $settings['channels']['email'] = [
            'enabled' => true,
            'credentials' => ['host' => 'tenant-smtp.invalid.test', 'port' => 587, 'from_address' => 'bookings@example.test'],
        ];
        BusinessCustomerNotificationSettings::query()->create(['business_id' => $business->id, 'settings' => $settings]);
        config(['mail.default' => 'log']);
        $this->actingAs($owner, 'web');

        $this->assertFalse(app(ChangeAccountEmail::class)->isAvailable());
        $this->get($this->profileUrl())->assertOk()
            ->assertSee(__('profile.email_unavailable', locale: 'en'))
            ->assertDontSee('wire:submit="requestEmailChange"', false);
        $this->requestThroughProfile('blocked-new-address@example.test')->assertHasErrors('emailData.email');
        $this->assertNull(app(ChangeAccountEmail::class)->pendingFor($owner));
        Mail::assertNothingSent();
    }

    public function test_verification_requires_the_requesting_signed_in_account_and_an_untampered_link(): void
    {
        [, $owner] = $this->context();
        [, $other] = $this->context();
        $this->actingAs($owner, 'web');
        $link = $this->requestEmail($owner, 'account-owned-link@example.test');

        Auth::guard('web')->logout();
        $this->get($link)->assertRedirect(route('filament.admin.auth.login'));
        $this->actingAs($other, 'web')->get($link)->assertForbidden();
        $this->assertSame($other->email, $other->fresh()->email);
        $this->assertSame($owner->email, $owner->fresh()->email);

        Auth::guard('web')->login($owner);
        $this->get($link.'&altered=1')->assertForbidden();
        $this->get($link)->assertRedirect($this->profileUrl());
        $this->assertSame('account-owned-link@example.test', $owner->fresh()->email);
    }

    public function test_new_request_supersedes_the_old_link_and_remaining_link_expires(): void
    {
        [, $owner] = $this->context();
        $this->actingAs($owner, 'web');
        $oldLink = $this->requestEmail($owner, 'first-pending-address@example.test');
        $newLink = $this->requestEmail($owner, 'second-pending-address@example.test');

        $this->get($oldLink)->assertForbidden();
        $this->assertSame('second-pending-address@example.test', app(ChangeAccountEmail::class)->pendingFor($owner)->email);

        $this->travel(31)->minutes();
        $this->get($newLink)->assertForbidden();
        $this->assertNull(app(ChangeAccountEmail::class)->pendingFor($owner));
        $this->assertSame($owner->email, $owner->fresh()->email);
        Mail::assertNotSent(EmailChangeCompleted::class);
    }

    public function test_password_change_and_later_email_conflict_prevent_pending_verification(): void
    {
        [, $owner] = $this->context();
        $this->actingAs($owner, 'web');
        $newEmail = 'claimed-before-verification@example.test';
        $link = $this->requestEmail($owner, $newEmail);
        $laterOwner = User::factory()->create(['email' => $newEmail]);

        $this->get($link)->assertForbidden();
        $this->assertSame($newEmail, $laterOwner->fresh()->email);

        $passwordBoundLink = $this->requestEmail($owner, 'invalid-after-password-change@example.test');
        $this->profile()->set('passwordData', [
            'current_password' => self::PASSWORD,
            'password' => 'ReplacedProfilePassword123',
            'password_confirmation' => 'ReplacedProfilePassword123',
        ])->call('updatePassword')->assertHasNoErrors();

        $this->actingAs($owner->fresh(), 'web')->get($passwordBoundLink)->assertForbidden();
        $this->assertNull(app(ChangeAccountEmail::class)->pendingFor($owner->fresh()));
        $this->assertSame($owner->email, $owner->fresh()->email);
        Mail::assertNotSent(EmailChangeCompleted::class);
    }

    public function test_email_request_and_verification_are_rate_limited(): void
    {
        [, $owner] = $this->context();
        $this->actingAs($owner, 'web');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $link = $this->requestEmail($owner, "pending-{$attempt}@example.test");
        }
        $this->requestThroughProfile('over-request-limit@example.test')->assertHasErrors('emailData.email');
        Mail::assertSent(VerifyEmailChange::class, 5);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->get($link.'&altered=1')->assertForbidden();
        }
        $this->get($link)->assertTooManyRequests();
        $this->assertSame($owner->email, $owner->fresh()->email);

        $this->travel(2)->minutes();
        $this->get($link)->assertRedirect($this->profileUrl());
        $this->assertSame('pending-5@example.test', $owner->fresh()->email);
    }

    /** @return array{Business, User} */
    private function context(): array
    {
        $business = Business::query()->create([
            'name' => 'Account email test business', 'slug' => 'email-change-'.Str::lower(Str::random(12)),
            'timezone' => 'Asia/Riyadh', 'default_currency' => 'SAR', 'is_active' => true,
        ]);
        $owner = User::factory()->create(['password' => self::PASSWORD]);
        $owner->forceFill(['preferred_locale' => 'en'])->save();
        app(ManageBusinessMemberships::class)->save($business, $owner, BusinessRole::Owner, BranchAccessScope::All, []);

        return [$business, $owner];
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

    private function requestThroughProfile(string $email, string $password = self::PASSWORD): Testable
    {
        return $this->profile()->set('emailData', ['email' => $email, 'current_password' => $password])->call('requestEmailChange');
    }

    private function requestEmail(User $user, string $email): string
    {
        app(ChangeAccountEmail::class)->request($user, $email, self::PASSWORD);

        return $this->lastLink($email);
    }

    private function lastLink(string $email): string
    {
        Mail::assertSent(VerifyEmailChange::class, fn (VerifyEmailChange $mail): bool => $mail->hasTo($email) && $mail->mailer === 'smtp');

        return Mail::sent(VerifyEmailChange::class, fn (VerifyEmailChange $mail): bool => $mail->hasTo($email))->last()->verificationUrl;
    }
}
