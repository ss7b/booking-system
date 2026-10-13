<?php

namespace App\Domain\Notifications;

use App\Enums\BookingStatus;
use App\Jobs\DeliverCustomerNotification;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessCustomerNotificationSettings;
use App\Models\CustomerNotification;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CustomerNotificationService
{
    public const EVENTS = [
        'booking.created',
        'booking.reminder',
        'booking.rescheduled',
        'booking.cancelled',
    ];

    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    public const PLACEHOLDERS = [
        'customer_name',
        'business_name',
        'booking_reference',
        'service_name',
        'location_name',
        'appointment_datetime',
        'timezone',
    ];

    public function settings(Business $business): BusinessCustomerNotificationSettings
    {
        return BusinessCustomerNotificationSettings::query()->firstOrCreate(
            ['business_id' => $business->getKey()],
            ['settings' => BusinessCustomerNotificationSettings::defaults()],
        );
    }

    public function processOutboxEvent(string $eventName, array $payload): ?CustomerNotification
    {
        $eventType = match ($eventName) {
            'BookingConfirmed' => (! isset($payload['from_status']) || $payload['from_status'] === BookingStatus::Held->value)
                ? 'booking.created'
                : null,
            'BookingRescheduled' => 'booking.rescheduled',
            'BookingCancelled' => 'booking.cancelled',
            default => null,
        };

        if ($eventType === null || ! is_string($payload['booking_id'] ?? null) || ! is_string($payload['event_id'] ?? null)) {
            return null;
        }

        $booking = Booking::query()->with(['business', 'customer', 'location'])->find($payload['booking_id']);
        if (! $booking || ! $booking->customer || ! $booking->business || ! $this->eventMatchesCurrentState($booking, $eventType, $payload['event_id'])) {
            return null;
        }

        $storedSettings = BusinessCustomerNotificationSettings::query()->find($booking->business_id);
        if (! $storedSettings) {
            return null;
        }

        $settings = $storedSettings->settings;
        $rule = $settings['rules'][$eventType] ?? [];
        $channels = $this->enabledChannels($settings, $rule);
        if (! ($rule['enabled'] ?? false) || $channels === []) {
            return null;
        }

        return $this->queueNotification(
            $booking,
            $eventType,
            $channels,
            hash('sha256', $payload['event_id'].':'.$eventType),
            $payload['event_id'],
        );
    }

    public function dispatchDueReminders(): int
    {
        $dispatched = 0;

        foreach (BusinessCustomerNotificationSettings::query()->get() as $storedSettings) {
            $settings = $storedSettings->settings;
            $rule = $settings['rules']['booking.reminder'] ?? [];
            $channels = $this->enabledChannels($settings, $rule);
            $leadMinutes = (int) ($rule['lead_minutes'] ?? 0);

            if (! ($rule['enabled'] ?? false) || $channels === [] || $leadMinutes < 1 || $leadMinutes > 525600) {
                continue;
            }

            $this->recordMissedReminders($storedSettings->business_id, $leadMinutes, $channels);
            $windowEnd = now()->addMinutes($leadMinutes);
            $windowStart = $windowEnd->copy()->subMinute();

            Booking::query()
                ->with('customer')
                ->where('business_id', $storedSettings->business_id)
                ->where('status', BookingStatus::Confirmed->value)
                ->where('starts_at', '>', now())
                ->where('starts_at', '>', $windowStart)
                ->where('starts_at', '<=', $windowEnd)
                ->orderBy('starts_at')
                ->chunkById(100, function ($bookings) use ($channels, &$dispatched): void {
                    foreach ($bookings as $booking) {
                        if (! $booking->customer) {
                            continue;
                        }

                        $key = hash('sha256', 'reminder:'.$booking->getKey().':'.$this->appointmentFingerprint($booking));
                        $this->queueNotification($booking, 'booking.reminder', $channels, $key);
                        $dispatched++;
                    }
                });
        }

        return $dispatched;
    }

    private function recordMissedReminders(string $businessId, int $leadMinutes, array $channels): void
    {
        $missedBefore = now()->addMinutes($leadMinutes)->subMinute();

        Booking::query()
            ->where('business_id', $businessId)
            ->where('status', BookingStatus::Confirmed->value)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', $missedBefore)
            ->orderBy('starts_at')
            ->chunkById(100, function ($bookings) use ($businessId, $channels): void {
                foreach ($bookings as $booking) {
                    if (! $booking->customer_id) {
                        continue;
                    }

                    CustomerNotification::query()->firstOrCreate(
                        ['idempotency_key' => hash('sha256', 'reminder:'.$booking->getKey().':'.$this->appointmentFingerprint($booking))],
                        [
                            'business_id' => $businessId,
                            'booking_id' => $booking->getKey(),
                            'customer_id' => $booking->customer_id,
                            'event_type' => 'booking.reminder',
                            'channels' => $channels,
                            'appointment_starts_at' => $booking->starts_at,
                            'appointment_fingerprint' => $this->appointmentFingerprint($booking),
                            'appointment_starts_at' => $booking->starts_at,
                            'status' => 'skipped',
                            'failed_at' => now(),
                            'error_code' => 'reminder_time_passed',
                            'error_message' => 'The scheduled reminder time passed before the scheduler could queue it.',
                        ],
                    );
                }
            });
    }

    public function dispatchDueRetries(): int
    {
        $dispatched = 0;

        CustomerNotification::query()
            ->where(function ($query): void {
                $query->where('status', 'queued')
                    ->orWhere(function ($query): void {
                        $query->where('status', 'retry_wait')->where('next_attempt_at', '<=', now());
                    });
            })
            ->orderBy('next_attempt_at')
            ->limit(500)
            ->pluck('id')
            ->each(function (string $id) use (&$dispatched): void {
                $ready = DB::transaction(function () use ($id): bool {
                    $notification = CustomerNotification::query()->lockForUpdate()->find($id);
                    if (! $notification
                        || ! in_array($notification->status, ['queued', 'retry_wait'], true)
                        || $notification->next_attempt_at?->isFuture()) {
                        return false;
                    }

                    $notification->status = 'queued';
                    $notification->next_attempt_at = null;
                    $notification->save();

                    return true;
                });

                if ($ready) {
                    DeliverCustomerNotification::dispatch($id)->afterCommit();
                    $dispatched++;
                }
            });

        return $dispatched;
    }

    public function isCurrent(CustomerNotification $notification): bool
    {
        $booking = $notification->booking()->with(['business', 'location'])->first();
        if (! $booking) {
            return false;
        }

        if ($notification->event_type === 'booking.reminder') {
            return $booking->status === BookingStatus::Confirmed
                && $booking->starts_at->isFuture()
                && $notification->appointment_starts_at?->equalTo($booking->starts_at)
                && hash_equals((string) $notification->appointment_fingerprint, $this->appointmentFingerprint($booking));
        }

        return is_string($notification->source_outbox_event_id)
            && $this->eventMatchesCurrentState($booking, $notification->event_type, $notification->source_outbox_event_id);
    }

    public function resend(CustomerNotification $notification, User $actor): CustomerNotification
    {
        abort_unless($notification->status === 'failed', 422, 'Only failed notifications may be resent.');

        $notification->forceFill([
            'channel_index' => 0,
            'channel_attempts' => 0,
            'status' => 'queued',
            'next_attempt_at' => null,
            'accepted_at' => null,
            'delivered_at' => null,
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
            'resent_by' => $actor->getKey(),
            'resent_at' => now(),
        ])->save();

        DeliverCustomerNotification::dispatch((string) $notification->getKey())->afterCommit();

        return $notification->refresh();
    }

    public function enabledChannels(array $settings, array $rule): array
    {
        return array_values(array_unique(array_filter(
            $rule['channels'] ?? [],
            fn (mixed $channel): bool => is_string($channel)
                && in_array($channel, self::CHANNELS, true)
                && (bool) ($settings['channels'][$channel]['enabled'] ?? false),
        )));
    }

    public function templateData(Booking $booking): array
    {
        $timezone = $booking->location?->timezone ?: $booking->business->timezone;

        return [
            'customer_name' => $booking->customer?->name ?? '',
            'business_name' => $booking->business->name,
            'booking_reference' => $booking->reference,
            'service_name' => $booking->service_name_snapshot,
            'location_name' => $booking->location?->name ?? '',
            'appointment_datetime' => $booking->starts_at->timezone($timezone)->format('M j, Y g:i A'),
            'timezone' => $timezone,
        ];
    }

    public function appointmentFingerprint(Booking $booking): string
    {
        return hash('sha256', implode(':', [
            $booking->starts_at->format('U.u'),
            (string) $booking->staff_id,
            (string) $booking->location_id,
        ]));
    }

    public function render(string $template, array $data): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function (array $match) use ($data): string {
            return in_array($match[1], self::PLACEHOLDERS, true) ? (string) ($data[$match[1]] ?? '') : '';
        }, $template) ?? $template;
    }

    private function queueNotification(
        Booking $booking,
        string $eventType,
        array $channels,
        string $idempotencyKey,
        ?string $sourceEventId = null,
    ): CustomerNotification {
        $notification = DB::transaction(function () use ($booking, $eventType, $channels, $idempotencyKey, $sourceEventId): CustomerNotification {
            $notification = CustomerNotification::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'business_id' => $booking->business_id,
                    'booking_id' => $booking->getKey(),
                    'customer_id' => $booking->customer_id,
                    'source_outbox_event_id' => $sourceEventId,
                    'appointment_starts_at' => $eventType === 'booking.reminder' ? $booking->starts_at : null,
                    'appointment_fingerprint' => $eventType === 'booking.reminder' ? $this->appointmentFingerprint($booking) : null,
                    'event_type' => $eventType,
                    'channels' => $channels,
                    'status' => 'queued',
                ],
            );

            DB::afterCommit(fn () => DeliverCustomerNotification::dispatch((string) $notification->getKey())->afterCommit());

            return $notification;
        });

        return $notification;
    }

    private function eventMatchesCurrentState(Booking $booking, string $eventType, string $sourceEventId): bool
    {
        $validStatus = match ($eventType) {
            'booking.created', 'booking.rescheduled' => in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Held], true),
            'booking.cancelled' => $booking->status === BookingStatus::Cancelled,
            default => false,
        };

        if (! $validStatus) {
            return false;
        }

        $source = OutboxEvent::query()->find($sourceEventId);
        if (! $source
            || (string) $source->business_id !== (string) $booking->business_id
            || $source->aggregate_type !== 'Booking'
            || (string) $source->aggregate_id !== (string) $booking->getKey()) {
            return false;
        }

        return ! OutboxEvent::query()
            ->where('aggregate_type', 'Booking')
            ->where('aggregate_id', $booking->getKey())
            ->where(function ($query) use ($source): void {
                $query->where('occurred_at', '>', $source->occurred_at)
                    ->orWhere(function ($query) use ($source): void {
                        $query->where('occurred_at', $source->occurred_at)->where('id', '>', $source->getKey());
                    });
            })
            ->exists();
    }
}
