<?php

namespace App\Filament\Pages;

use App\Domain\Reports\BookingReports;
use App\Models\Business;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

class Reports extends Page
{
    protected static string $view = 'filament.pages.reports-redirect';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $business = Filament::getTenant();

        return $user instanceof User
            && $business instanceof Business
            && Gate::forUser($user)->allows(BookingReports::VIEW, $business);
    }

    public function mount(): void
    {
        $business = Filament::getTenant();
        abort_unless($business instanceof Business, 404);
        abort_unless(static::canAccess(), 403);

        $this->redirect(Dashboard::getUrl(['tenant' => $business, 'tab' => 'reports']));
    }
}
