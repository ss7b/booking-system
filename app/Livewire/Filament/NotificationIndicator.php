<?php

namespace App\Livewire\Filament;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Notifications\NotificationCenter as NotificationManager;
use App\Filament\Pages\NotificationCenter as NotificationCenterPage;
use App\Models\Business;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationIndicator extends Component
{
    #[Locked]
    public int $unreadCount = 0;

    #[Locked]
    public string $cursorCreatedAt = '';

    #[Locked]
    public string $cursorId = '';

    public function mount(NotificationManager $notifications): void
    {
        [$user, $business] = $this->context();

        DB::transaction(function () use ($notifications, $user, $business): void {
            $query = $notifications->query($user, $business);
            $this->unreadCount = (clone $query)->whereNull('read_at')->count();
            $latest = (clone $query)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['id', 'created_at']);

            $this->cursorCreatedAt = $latest?->created_at->format('Y-m-d H:i:s.u') ?? now()->format('Y-m-d H:i:s.u');
            $this->cursorId = (string) ($latest?->getKey() ?? '');
        });
    }

    public function poll(NotificationManager $notifications): int
    {
        [$user, $business] = $this->context();
        $query = $notifications->query($user, $business)
            ->where(function ($query): void {
                $query->where('created_at', '>', $this->cursorCreatedAt);

                if ($this->cursorId !== '') {
                    $query->orWhere(function ($query): void {
                        $query->where('created_at', $this->cursorCreatedAt)
                            ->where('id', '>', $this->cursorId);
                    });
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(25);
        $newNotifications = $query->get(['id', 'notification_type', 'created_at']);

        if ($newNotifications->isNotEmpty()) {
            $latest = $newNotifications->last();
            $this->cursorCreatedAt = $latest->created_at->format('Y-m-d H:i:s.u');
            $this->cursorId = (string) $latest->getKey();
        }

        $previousCount = $this->unreadCount;
        $this->unreadCount = $notifications->query($user, $business)->whereNull('read_at')->count();

        if ($newNotifications->isNotEmpty()) {
            $newCount = $newNotifications->count();
            Notification::make()
                ->title($newCount === 1 ? 'New booking notification' : "{$newCount} new booking notifications")
                ->body('Open the notification center to view the update.')
                ->info()
                ->send();
        }

        if ($newNotifications->isNotEmpty() || $previousCount !== $this->unreadCount) {
            $this->dispatch('admin-notifications-updated', count: $this->unreadCount);
        }

        return $newNotifications->count();
    }

    #[On('admin-notifications-updated')]
    public function syncUnreadCount(int $count): void
    {
        $this->unreadCount = max(0, $count);
    }

    public function notificationCenterUrl(): string
    {
        [, $business] = $this->context();

        return NotificationCenterPage::getUrl(tenant: $business);
    }

    private function context(): array
    {
        $user = Filament::auth()->user();
        $business = Filament::getTenant();

        abort_unless(
            $user instanceof User
                && $business instanceof Business
                && app(BusinessAccess::class)->isMember($user, $business),
            403,
        );

        return [$user, $business];
    }
}