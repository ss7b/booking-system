<?php

namespace App\Filament\Widgets;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Notifications\NotificationCenter;
use App\Enums\BookingStatus;
use App\Enums\BusinessRole;
use App\Filament\Pages\NotificationCenter as NotificationCenterPage;
use App\Filament\Resources\BookingResource;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

class OperationalDashboardWidget extends Widget
{
    protected static string $view = 'filament.widgets.operational-dashboard';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    private const APPOINTMENT_STATUSES = [BookingStatus::Held, BookingStatus::Confirmed, BookingStatus::CheckedIn];

    public static function canView(): bool
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();

        return $business instanceof Business && $user instanceof User
            && in_array(app(BusinessAccess::class)->role($user, $business), [BusinessRole::Receptionist, BusinessRole::Staff], true);
    }

    protected function getViewData(): array
    {
        $business = Filament::getTenant();
        $user = Filament::auth()->user();
        abort_unless(static::canView(), 403);

        $access = app(BusinessAccess::class);
        $now = CarbonImmutable::now('UTC');
        $locationsByTimezone = $access->locations($user, $business)
            ->get(['id', 'timezone'])
            ->groupBy(fn (Location $location): string => $location->timezone ?: ($business->timezone ?: config('app.timezone')));
        $bookings = $access->bookings($user, $business)
            ->select(['id', 'location_id', 'reference', 'service_name_snapshot', 'starts_at', 'status'])
            ->with('location:id,name,timezone')
            ->where(fn (Builder $query) => $query
                ->where('status', '!=', BookingStatus::Held->value)
                ->orWhere('hold_expires_at', '>', $now))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(6);

        $today = (clone $bookings)
            ->whereIn('status', [...self::APPOINTMENT_STATUSES, BookingStatus::Completed, BookingStatus::NoShow])
            ->where(function (Builder $query) use ($locationsByTimezone, $now): void {
                $query->whereRaw('1 = 0');

                foreach ($locationsByTimezone as $timezone => $locations) {
                    $localToday = $now->setTimezone($timezone)->startOfDay();

                    $query->orWhere(fn (Builder $day) => $day
                        ->whereIn('location_id', $locations->modelKeys())
                        ->where('starts_at', '>=', $localToday->utc())
                        ->where('starts_at', '<', $localToday->addDay()->startOfDay()->utc()));
                }
            })
            ->get();

        $upcoming = $bookings->whereIn('status', self::APPOINTMENT_STATUSES)
            ->where('starts_at', '>=', $now)
            ->get();

        $notifications = app(NotificationCenter::class)->query($user, $business)
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'notification_type', 'created_at', 'read_at']);

        $bookingListUrl = BookingResource::canAccess() ? BookingResource::getUrl('index', ['tenant' => $business]) : null;

        return [
            'business' => $business,
            'today' => $today,
            'upcoming' => $upcoming,
            'notifications' => $notifications,
            'bookingListUrl' => $bookingListUrl,
            'newBookingUrl' => $bookingListUrl && $access->canManageBookings($user, $business)
                ? BookingResource::getUrl('index', ['tenant' => $business, 'tableAction' => 'create_booking'])
                : null,
            'notificationCenterUrl' => NotificationCenterPage::canAccess()
                ? NotificationCenterPage::getUrl(tenant: $business)
                : null,
        ];
    }
}
