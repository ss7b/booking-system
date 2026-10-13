<?php

namespace App\Domain\Notifications;

use App\Jobs\DeliverCustomerNotification;
use App\Models\BusinessCustomerNotificationSettings;
use App\Models\CustomerNotification;
use App\Models\CustomerNotificationAttempt;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class CustomerNotificationDelivery
{
    public function __construct(private readonly CustomerNotificationService $notifications) {}

    public function deliver(string $notificationId): void
    {
        $notification = DB::transaction(function () use ($notificationId): ?CustomerNotification {
            $notification = CustomerNotification::query()->lockForUpdate()->find($notificationId);

            if (! $notification
                || ! in_array($notification->status, ['queued', 'retry_wait'], true)
                || $notification->next_attempt_at?->isFuture()) {
                return null;
            }

            $notification->status = 'processing';
            $notification->next_attempt_at = null;
            $notification->save();

            return $notification->load(['business', 'booking.customer', 'booking.location', 'customer']);
        });

        if (! $notification) {
            return;
        }

        if (! $this->notifications->isCurrent($notification)) {
            $notification->forceFill(['status' => 'superseded', 'failed_at' => now()])->save();

            return;
        }

        $storedSettings = BusinessCustomerNotificationSettings::query()->find($notification->business_id);
        $settings = $storedSettings?->settings ?? BusinessCustomerNotificationSettings::defaults();
        $channel = $notification->channels[$notification->channel_index] ?? null;

        if (! is_string($channel)) {
            $this->finishFailed($notification, 'channels_exhausted', 'No configured channel could deliver this notification.');

            return;
        }

        $channelSettings = $settings['channels'][$channel] ?? [];
        if (! ($channelSettings['enabled'] ?? false) || ! $this->hasProviderConfiguration($channel, $channelSettings['credentials'] ?? [])) {
            $this->recordSkipped($notification, $channel, 'provider_unconfigured', 'Channel configuration is incomplete.');
            $this->advanceChannel($notification);

            return;
        }

        if (($channelSettings['suspended_at'] ?? null) !== null) {
            $this->recordSkipped($notification, $channel, 'provider_suspended', 'Provider is suspended pending administrator review.');
            $this->advanceChannel($notification);

            return;
        }

        $openUntil = Cache::get($this->circuitKey($notification, $channel));
        if (is_numeric($openUntil) && (int) $openUntil > now()->timestamp) {
            $this->recordSkipped($notification, $channel, 'circuit_open', 'Provider is temporarily unavailable.');
            $this->advanceChannel($notification);

            return;
        }

        $recoveryLock = null;
        if (is_numeric($openUntil)) {
            $recoveryLock = Cache::lock($this->recoveryKey($notification, $channel), 120);
            if (! $recoveryLock->get()) {
                $this->recordSkipped($notification, $channel, 'recovery_in_progress', 'Provider recovery is already in progress.');
                $this->advanceChannel($notification);

                return;
            }
        }

        $template = $settings['templates'][$notification->event_type][$channel] ?? [];
        if (! ($template['enabled'] ?? false)) {
            $this->recordSkipped($notification, $channel, 'template_disabled', 'No enabled template is configured for this channel.');
            $recoveryLock?->release();
            $this->advanceChannel($notification);

            return;
        }

        $recipient = $this->recipient($notification, $channel);
        if ($recipient === null) {
            $this->recordSkipped($notification, $channel, 'invalid_recipient', 'The customer has no valid destination for this channel.');
            $recoveryLock?->release();
            $this->advanceChannel($notification);

            return;
        }

        if ($channel === 'whatsapp' && ! preg_match('/^HX[a-fA-F0-9]{32}$/', (string) ($template['content_sid'] ?? ''))) {
            $this->recordSkipped($notification, $channel, 'whatsapp_template_required', 'WhatsApp requires an approved Twilio Content template.');
            $recoveryLock?->release();
            $this->advanceChannel($notification);

            return;
        }

        $attemptNumber = $notification->attempts()->where('channel', $channel)->count() + 1;
        $attempt = CustomerNotificationAttempt::query()->create([
            'customer_notification_id' => $notification->getKey(),
            'channel' => $channel,
            'attempt_number' => $attemptNumber,
            'status' => 'sending',
            'initiated_by' => $notification->resent_by,
            'created_at' => now(),
        ]);
        $notification->increment('channel_attempts');
        $notification->increment('total_attempts');

        try {
            $result = $channel === 'email'
                ? $this->sendEmail($recipient, $template, $channelSettings['credentials'], $notification)
                : $this->sendTwilio($recipient, $template, $channelSettings['credentials'], $notification, $channel, (string) $attempt->getKey());
        } catch (TransportExceptionInterface $exception) {
            $message = strtolower($exception->getMessage());
            $isAuthenticationFailure = str_contains($message, 'auth') || str_contains($message, 'credential');
            $isTransientConnectionFailure = str_contains($message, 'connection could not be established')
                || str_contains($message, 'failed to connect')
                || str_contains($message, 'connection refused');
            $result = [
                'status' => $isAuthenticationFailure ? 'suspended' : ($isTransientConnectionFailure ? 'transient' : 'unknown'),
                'error_code' => $isAuthenticationFailure
                    ? 'provider_authentication'
                    : ($isTransientConnectionFailure ? 'smtp_connection' : 'delivery_outcome_unknown'),
                'error_message' => $isAuthenticationFailure
                    ? 'SMTP authentication failed; credentials require review.'
                    : ($isTransientConnectionFailure
                        ? 'SMTP connection could not be established before message submission.'
                        : 'SMTP did not confirm whether the message was accepted. Automatic retry was stopped to avoid duplicates.'),
            ];
        } catch (\Throwable) {
            $result = [
                'status' => 'unknown',
                'error_code' => 'delivery_outcome_unknown',
                'error_message' => 'The provider did not confirm whether the message was accepted. Automatic retry was stopped to avoid duplicates.',
            ];
        } finally {
            $recoveryLock?->release();
        }

        $result['recovery_attempt'] = $recoveryLock !== null;

        $attempt->forceFill([
            'status' => $result['status'],
            'provider_message_id' => $result['provider_message_id'] ?? null,
            'error_code' => $result['error_code'] ?? null,
            'error_message' => $result['error_message'] ?? null,
        ])->save();

        match ($result['status']) {
            'accepted' => $this->accepted($notification, $channel),
            'delivered' => $this->delivered($notification),
            'transient' => $this->retryOrAdvance($notification, $channel, $result),
            'suspended' => $this->suspendProvider($notification, $channel, $settings, $storedSettings, $result),
            'unknown' => $notification->forceFill([
                'status' => 'unknown',
                'error_code' => $result['error_code'],
                'error_message' => $result['error_message'],
            ])->save(),
            default => $this->permanentFailure($notification, $channel, $result),
        };
    }

    public function fallbackAfterUndelivered(string $notificationId, string $channel, string $errorCode): void
    {
        $notification = CustomerNotification::query()->find($notificationId);
        if (! $notification || $notification->status !== 'accepted') {
            return;
        }

        if (($notification->channels[$notification->channel_index] ?? null) !== $channel) {
            return;
        }

        $this->advanceChannel($notification, $errorCode, 'Twilio confirmed the message was not delivered.');
    }

    private function sendEmail(string $recipient, array $template, array $credentials, CustomerNotification $notification): array
    {
        $transport = new EsmtpTransport(
            $credentials['host'],
            (int) $credentials['port'],
            ($credentials['encryption'] ?? 'starttls') === 'ssl',
        );
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(10);
        }
        $transport->setAutoTls(($credentials['encryption'] ?? 'starttls') === 'starttls');

        if (($credentials['username'] ?? '') !== '') {
            $transport->setUsername($credentials['username']);
            $transport->setPassword($credentials['password'] ?? '');
        }

        $data = $this->notifications->templateData($notification->booking);
        $email = (new Email)
            ->from(new Address($credentials['from_address'], $credentials['from_name'] ?? $notification->business->name))
            ->to($recipient)
            ->subject($this->notifications->render((string) ($template['subject'] ?? ''), $data))
            ->text($this->notifications->render((string) ($template['body'] ?? ''), $data));

        (new Mailer($transport))->send($email);

        return ['status' => 'accepted'];
    }

    private function sendTwilio(
        string $recipient,
        array $template,
        array $credentials,
        CustomerNotification $notification,
        string $channel,
        string $attemptId,
    ): array {
        $data = $this->notifications->templateData($notification->booking);
        $sender = trim($credentials['from'] ?? '');
        $destination = $recipient;
        if ($channel === 'whatsapp') {
            $sender = str_starts_with($sender, 'whatsapp:') ? $sender : 'whatsapp:'.$sender;
            $destination = 'whatsapp:'.$destination;
        }

        $payload = [
            'From' => $sender,
            'To' => $destination,
            'StatusCallback' => route('customer-notifications.twilio-status', ['attempt' => $attemptId]),
        ];

        if ($channel === 'whatsapp') {
            $payload['ContentSid'] = $template['content_sid'];
            $payload['ContentVariables'] = json_encode([
                '1' => $data['customer_name'],
                '2' => $data['business_name'],
                '3' => $data['booking_reference'],
                '4' => $data['appointment_datetime'],
            ], JSON_THROW_ON_ERROR);
        } else {
            $payload['Body'] = $this->notifications->render((string) ($template['body'] ?? ''), $data);
        }

        $response = Http::withBasicAuth($credentials['account_sid'], $credentials['auth_token'])
            ->asForm()
            ->timeout(15)
            ->post('https://api.twilio.com/2010-04-01/Accounts/'.$credentials['account_sid'].'/Messages.json', $payload);

        if ($response->successful()) {
            return ['status' => 'accepted', 'provider_message_id' => $response->json('sid')];
        }

        $code = (string) ($response->json('code') ?? $response->status());
        if (in_array($response->status(), [401, 403], true)) {
            return ['status' => 'suspended', 'error_code' => $code, 'error_message' => 'Twilio rejected the configured credentials.'];
        }
        if ($response->status() === 429 || $response->serverError()) {
            return [
                'status' => 'transient',
                'error_code' => $code,
                'error_message' => 'Twilio temporarily rejected the message.',
                'retry_after' => $response->header('Retry-After'),
            ];
        }

        return ['status' => 'permanent', 'error_code' => $code, 'error_message' => 'Twilio rejected the message. Check recipient and provider configuration.'];
    }

    private function retryOrAdvance(CustomerNotification $notification, string $channel, array $result): void
    {
        $failures = $this->recordTransientFailure($notification, $channel);
        if ($result['recovery_attempt'] ?? false) {
            Cache::put($this->circuitKey($notification, $channel), now()->addMinutes(5)->timestamp, now()->addDay());
            $this->advanceChannel($notification, $result['error_code'], $result['error_message']);

            return;
        }

        if ($failures >= 5 || (int) $notification->channel_attempts >= 3) {
            if ($failures >= 5) {
                Cache::put($this->circuitKey($notification, $channel), now()->addMinutes(5)->timestamp, now()->addDay());
            }

            $this->advanceChannel($notification, $result['error_code'], $result['error_message']);

            return;
        }

        $retryAt = $this->retryTime($result['retry_after'] ?? null, (int) $notification->channel_attempts);
        $notification->forceFill([
            'status' => 'retry_wait',
            'next_attempt_at' => $retryAt,
            'error_code' => $result['error_code'],
            'error_message' => $result['error_message'],
        ])->save();

        if (config('queue.default') !== 'sync') {
            DeliverCustomerNotification::dispatch((string) $notification->getKey())->delay($retryAt);
        }
    }

    private function retryTime(mixed $retryAfter, int $attempt): CarbonImmutable
    {
        if (is_string($retryAfter) && $retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                return now()->addSeconds(max(1, (int) $retryAfter))->toImmutable();
            }

            try {
                return CarbonImmutable::parse($retryAfter)->max(now()->addSecond()->toImmutable());
            } catch (\Throwable) {
                // Fall through to the default retry schedule for malformed headers.
            }
        }

        return now()->addMinutes($attempt <= 1 ? 1 : 5)->toImmutable();
    }

    private function permanentFailure(CustomerNotification $notification, string $channel, array $result): void
    {
        $this->advanceChannel($notification, $result['error_code'] ?? 'provider_rejected', $result['error_message'] ?? 'Provider rejected the message.');
    }

    private function advanceChannel(CustomerNotification $notification, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $nextIndex = (int) $notification->channel_index + 1;
        if ($nextIndex >= count($notification->channels)) {
            $this->finishFailed($notification, $errorCode ?? 'channels_exhausted', $errorMessage ?? 'All configured channels were exhausted.');

            return;
        }

        $notification->forceFill([
            'channel_index' => $nextIndex,
            'channel_attempts' => 0,
            'status' => 'queued',
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ])->save();

        DeliverCustomerNotification::dispatch((string) $notification->getKey())->afterCommit();
    }

    private function recordSkipped(CustomerNotification $notification, string $channel, string $code, string $message): void
    {
        $attemptNumber = $notification->attempts()->where('channel', $channel)->count() + 1;
        $notification->increment('total_attempts');
        CustomerNotificationAttempt::query()->create([
            'customer_notification_id' => $notification->getKey(),
            'channel' => $channel,
            'attempt_number' => $attemptNumber,
            'status' => 'skipped',
            'error_code' => $code,
            'error_message' => $message,
            'initiated_by' => $notification->resent_by,
            'created_at' => now(),
        ]);
    }

    private function accepted(CustomerNotification $notification, string $channel): void
    {
        $notification->forceFill(['status' => 'accepted', 'accepted_at' => now(), 'error_code' => null, 'error_message' => null])->save();
        Cache::forget($this->circuitKey($notification, $channel));
        Cache::forget('customer-notifications:provider:'.$notification->business_id.':'.$channel.':failures');
    }

    private function delivered(CustomerNotification $notification): void
    {
        $notification->forceFill(['status' => 'delivered', 'accepted_at' => $notification->accepted_at ?? now(), 'delivered_at' => now()])->save();
    }

    private function finishFailed(CustomerNotification $notification, string $code, string $message): void
    {
        $notification->forceFill(['status' => 'failed', 'failed_at' => now(), 'error_code' => $code, 'error_message' => $message])->save();
        $this->alertBusinessOwners($notification, 'customer.notification_failed', 'A customer notification failed after all configured channels were exhausted.');
    }

    private function suspendProvider(
        CustomerNotification $notification,
        string $channel,
        array $settings,
        ?BusinessCustomerNotificationSettings $storedSettings,
        array $result,
    ): void {
        if ($storedSettings) {
            $settings['channels'][$channel]['suspended_at'] = now()->toIso8601String();
            $storedSettings->settings = $settings;
            $storedSettings->save();
        }

        $this->recordTransientFailure($notification, $channel, countFailure: false);
        $this->alertBusinessOwners(
            $notification,
            'customer.provider_attention',
            'A customer notification provider was suspended. Review its credentials and connection settings.',
            'provider:'.$channel.':'.($result['error_code'] ?? 'suspended'),
        );
        $this->advanceChannel($notification, $result['error_code'] ?? 'provider_suspended', $result['error_message'] ?? 'Provider requires administrator review.');
    }

    private function recordTransientFailure(CustomerNotification $notification, string $channel, bool $countFailure = true): int
    {
        if (! $countFailure) {
            return 0;
        }

        $base = 'customer-notifications:provider:'.$notification->business_id.':'.$channel;
        $lock = Cache::lock($base.':failure-lock', 5);

        return $lock->block(3, function () use ($base): int {
            Cache::add($base.':failures', 0, now()->addMinutes(2));
            $failures = Cache::increment($base.':failures');
            if ($failures >= 5) {
                Cache::put($base.':open-until', now()->addMinutes(5)->timestamp, now()->addDay());
            }

            return $failures;
        });
    }

    private function hasProviderConfiguration(string $channel, array $credentials): bool
    {
        return match ($channel) {
            'email' => filter_var($credentials['from_address'] ?? null, FILTER_VALIDATE_EMAIL)
                && filled($credentials['host'] ?? null)
                && (int) ($credentials['port'] ?? 0) > 0,
            'sms', 'whatsapp' => filled($credentials['account_sid'] ?? null)
                && filled($credentials['auth_token'] ?? null)
                && filled($credentials['from'] ?? null),
            default => false,
        };
    }

    private function recipient(CustomerNotification $notification, string $channel): ?string
    {
        $customer = $notification->customer;
        if (! $customer) {
            return null;
        }

        if ($channel === 'email') {
            return filter_var($customer->email, FILTER_VALIDATE_EMAIL) ? $customer->email : null;
        }

        $phone = preg_replace('/[^+\d]/', '', (string) $customer->phone);

        return preg_match('/^\+[1-9]\d{7,14}$/', $phone) ? $phone : null;
    }

    private function alertBusinessOwners(CustomerNotification $notification, string $type, string $message, ?string $deduplicationKey = null): void
    {
        $users = DB::table('business_user')
            ->join('users', 'users.id', '=', 'business_user.user_id')
            ->where('business_user.business_id', $notification->business_id)
            ->where('business_user.role', 'owner')
            ->where('users.is_active', true)
            ->pluck('users.id');

        foreach ($users as $userId) {
            UserNotification::query()->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'business_id' => $notification->business_id,
                'user_id' => $userId,
                'booking_id' => null,
                'notification_type' => $type,
                'idempotency_key' => hash('sha256', $notification->business_id.':'.$type.':'.($deduplicationKey ?? (string) $notification->getKey())),
                'data' => json_encode(['message' => $message], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function circuitKey(CustomerNotification $notification, string $channel): string
    {
        return 'customer-notifications:provider:'.$notification->business_id.':'.$channel.':open-until';
    }

    private function recoveryKey(CustomerNotification $notification, string $channel): string
    {
        return 'customer-notifications:provider:'.$notification->business_id.':'.$channel.':recovery';
    }
}
