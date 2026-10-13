<?php

namespace App\Filament\Pages;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Notifications\NotificationCenter as NotificationManager;
use App\Domain\Notifications\NotificationPolicy;
use App\Enums\BusinessRole;
use App\Filament\Resources\BookingResource;
use App\Models\Business;
use App\Models\UserNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class NotificationCenter extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationGroup = 'Booking Management';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $slug = 'notifications';

    protected static string $view = 'filament.pages.notification-center';

    public array $preferences = [];

    public array $settings = [];

    #[Locked]
    public int $unreadCount = 0;

    public function mount(): void
    {
        $center = app(NotificationManager::class);
        $user = Filament::auth()->user();
        $business = $this->business();
        $this->unreadCount = $center->query($user, $business)->whereNull('read_at')->count();

        $this->preferences = collect($center->preferences($user, $business))
            ->mapWithKeys(fn (array $preference): array => [str_replace('.', '_', $preference['notification_type']) => $preference['enabled']])
            ->all();

        if (app(BusinessAccess::class)->canManageBusinessSettings($user, $business)) {
            $this->settings = collect($center->businessSettings($business))
                ->map(fn (array $setting): array => [
                    ...$setting,
                    'reminder_intervals' => implode(',', $setting['reminder_intervals']),
                ])
                ->all();
        }
    }

    public function getTitle(): string
    {
        return 'Notifications';
    }

    #[On('admin-notifications-updated')]
    public function syncUnreadCount(int $count): void
    {
        $this->unreadCount = max(0, $count);
    }

    public function markRead(string $notificationId): void
    {
        app(NotificationManager::class)->markRead(Filament::auth()->user(), $this->business(), $notificationId);
        $this->dispatchUnreadCount();
    }

    public function markAllRead(): void
    {
        app(NotificationManager::class)->markAllRead(Filament::auth()->user(), $this->business());
        $this->dispatchUnreadCount();
    }

    public function savePreferences(): void
    {
        $preferences = [];
        foreach (NotificationPolicy::TYPES as $type) {
            $key = str_replace('.', '_', $type);
            $preferences[] = ['notification_type' => $type, 'enabled' => (bool) ($this->preferences[$key] ?? false)];
        }

        try {
            app(NotificationManager::class)->savePreferences(Filament::auth()->user(), $this->business(), $preferences);
            Notification::make()->title('Notification preferences saved')->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Could not save notification preferences')
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();
        }
    }

    public function saveSettings(): void
    {
        $user = Filament::auth()->user();
        $business = $this->business();
        abort_unless(app(BusinessAccess::class)->canManageBusinessSettings($user, $business), 403);

        $rules = array_map(function (array $setting): array {
            $intervals = $setting['notification_type'] === 'booking.reminder_due'
                ? array_values(array_filter(array_map('trim', explode(',', (string) $setting['reminder_intervals'])), fn (string $value): bool => $value !== ''))
                : [];

            return [
                'role' => $setting['role'],
                'notification_type' => $setting['notification_type'],
                'enabled' => (bool) $setting['enabled'],
                'reminder_intervals' => $intervals,
            ];
        }, $this->settings);

        try {
            Validator::make(['rules' => $rules], [
                'rules' => ['required', 'array'],
                'rules.*.role' => ['required', 'in:'.implode(',', array_column(BusinessRole::cases(), 'value'))],
                'rules.*.notification_type' => ['required', 'in:'.implode(',', NotificationPolicy::TYPES)],
                'rules.*.enabled' => ['required', 'boolean'],
                'rules.*.reminder_intervals' => ['nullable', 'array', 'min:1', 'max:8'],
                'rules.*.reminder_intervals.*' => ['required', 'integer', 'min:1', 'max:525600', 'distinct'],
            ])->validate();

            app(NotificationManager::class)->saveBusinessSettings($business, $rules);
            Notification::make()->title('Business notification rules saved')->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Could not save business notification rules')
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();
        }
    }

    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        $business = $this->business();
        $notifications = app(NotificationManager::class)->query($user, $business)
            ->with('booking:id,reference,starts_at,location_id', 'booking.location:id,timezone')
            ->latest('created_at')
            ->paginate(20);

        $notifications->getCollection()->transform(function (UserNotification $notification) use ($business): UserNotification {
            $notification->setAttribute('booking_url', $notification->booking
                ? BookingResource::getUrl('index', ['tenant' => $business]).'?tableSearch='.urlencode($notification->booking->reference)
                : null);

            return $notification;
        });

        return [
            'notifications' => $notifications,
            'unreadCount' => $this->unreadCount,
            'business' => $business,
            'mandatoryTypes' => collect(NotificationPolicy::TYPES)->filter(function (string $type) use ($user, $business): bool {
                $role = app(BusinessAccess::class)->role($user, $business);

                return $role && app(NotificationPolicy::class)->isMandatory($type, $role);
            })->all(),
        ];
    }

    private function dispatchUnreadCount(): void
    {
        $this->unreadCount = app(NotificationManager::class)
            ->query(Filament::auth()->user(), $this->business())
            ->whereNull('read_at')
            ->count();

        $this->dispatch('admin-notifications-updated', count: $this->unreadCount);
    }

    private static function business(): Business
    {
        $business = Filament::getTenant();
        abort_unless($business instanceof Business, 404);

        return $business;
    }
}
