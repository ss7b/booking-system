<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource;
use App\Http\Middleware\SetFilamentLocale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        App::setLocale((string) config('app.locale'));
        Carbon::setLocale((string) config('app.locale'));

        parent::tearDown();
    }

    public function test_admin_login_defaults_to_arabic_rtl_and_guest_can_switch_to_english(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('نظام الحجوزات')
            ->assertSee('العربية')
            ->assertSee('English');

        $this->withHeader('referer', '/admin/login')
            ->post('/admin/locale', ['locale' => 'en'])
            ->assertRedirect('/admin/login')
            ->assertSessionHas('filament_locale', 'en');

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Booking System');
    }

    public function test_locale_is_saved_per_user_and_is_persisted_by_livewire_middleware(): void
    {
        $arabicUser = User::factory()->create();
        $englishUser = User::factory()->create();

        $this->actingAs($arabicUser)
            ->withHeader('referer', '/admin/demo/bookings')
            ->post('/admin/locale', ['locale' => 'ar'])
            ->assertRedirect('/admin/demo/bookings')
            ->assertSessionHas('filament_locale', 'ar');

        $this->actingAs($englishUser)
            ->withHeader('referer', '/admin/demo/bookings')
            ->post('/admin/locale', ['locale' => 'en'])
            ->assertRedirect('/admin/demo/bookings')
            ->assertSessionHas('filament_locale', 'en');

        $this->assertDatabaseHas('users', ['id' => $arabicUser->id, 'preferred_locale' => 'ar']);
        $this->assertDatabaseHas('users', ['id' => $englishUser->id, 'preferred_locale' => 'en']);
        $this->assertContains(SetFilamentLocale::class, Livewire::getPersistentMiddleware());
    }

    public function test_resource_labels_follow_current_locale_and_missing_translations_fall_back_to_english(): void
    {
        App::setLocale('ar');
        $this->assertSame('الحجوزات', BookingResource::getNavigationLabel());

        App::setLocale('en');
        $this->assertSame('Bookings', BookingResource::getNavigationLabel());

        Lang::addLines(['messages.english_only' => 'English fallback'], 'en');
        App::setLocale('ar');
        $this->assertSame('English fallback', __('messages.english_only'));
    }
}
