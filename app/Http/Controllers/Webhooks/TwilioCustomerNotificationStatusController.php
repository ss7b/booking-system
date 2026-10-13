<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Notifications\CustomerNotificationDelivery;
use App\Models\BusinessCustomerNotificationSettings;
use App\Models\CustomerNotificationAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TwilioCustomerNotificationStatusController
{
    public function __invoke(Request $request, string $attempt, CustomerNotificationDelivery $delivery): Response
    {
        $record = CustomerNotificationAttempt::query()
            ->with('notification.business')
            ->findOrFail($attempt);
        abort_unless(in_array($record->channel, ['sms', 'whatsapp'], true), 404);

        $storedSettings = BusinessCustomerNotificationSettings::query()->findOrFail($record->notification->business_id);
        $credentials = $storedSettings->settings['channels'][$record->channel]['credentials'] ?? [];
        $signature = $request->header('X-Twilio-Signature');
        abort_unless(is_string($signature) && $this->validSignature($request, $signature, (string) ($credentials['auth_token'] ?? '')), 403);

        if ($record->provider_message_id && $request->input('MessageSid') !== $record->provider_message_id) {
            abort(403);
        }

        $providerStatus = (string) $request->input('MessageStatus', '');
        $shouldFallback = DB::transaction(function () use ($record, $providerStatus, $request): bool {
            $attempt = CustomerNotificationAttempt::query()->lockForUpdate()->findOrFail($record->getKey());
            $notification = $attempt->notification()->lockForUpdate()->firstOrFail();

            if ($notification->status === 'delivered' || $attempt->status === 'failed') {
                return false;
            }

            if ($providerStatus === 'delivered') {
                $attempt->status = 'delivered';
                $notification->status = 'delivered';
                $notification->delivered_at = now();
            } elseif (in_array($providerStatus, ['failed', 'undelivered'], true)) {
                $attempt->status = 'failed';
                $attempt->error_code = (string) ($request->input('ErrorCode') ?? 'undelivered');
                $attempt->error_message = 'Twilio reported that the message was not delivered.';
                $attempt->save();

                return true;
            } else {
                $attempt->status = 'accepted';
                $notification->status = 'accepted';
            }

            $attempt->save();
            $notification->save();

            return false;
        });

        if ($shouldFallback) {
            $delivery->fallbackAfterUndelivered(
                (string) $record->customer_notification_id,
                $record->channel,
                (string) ($request->input('ErrorCode') ?? 'undelivered'),
            );
        }

        return response()->noContent();
    }

    private function validSignature(Request $request, string $provided, string $authToken): bool
    {
        if ($authToken === '') {
            return false;
        }

        $url = rtrim((string) config('app.url'), '/').$request->getRequestUri();
        $parameters = $request->post();
        ksort($parameters);
        foreach ($parameters as $key => $value) {
            if (is_scalar($value)) {
                $url .= $key.(string) $value;
            }
        }

        $expected = base64_encode(hash_hmac('sha1', $url, $authToken, true));

        return hash_equals($expected, $provided);
    }
}
