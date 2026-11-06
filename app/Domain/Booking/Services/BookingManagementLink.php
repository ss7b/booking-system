<?php

namespace App\Domain\Booking\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use JsonException;
use LogicException;

final class BookingManagementLink
{
    private const TOKEN_SCOPE = 'booking-management';

    public function createUrl(Booking $booking): ?string
    {
        $booking->loadMissing('business');
        $business = $booking->business;
        $baseUrl = data_get($business?->metadata, 'booking_management_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return null;
        }

        if (! self::isValidManagementUrl($baseUrl)) {
            throw new \InvalidArgumentException('The business booking management URL is invalid.');
        }

        if ($booking->status !== BookingStatus::Confirmed || $booking->ends_at->lessThanOrEqualTo(now())) {
            return null;
        }

        $payload = [
            'scope' => self::TOKEN_SCOPE,
            'business_id' => (string) $business->getKey(),
            'booking_id' => (string) $booking->getKey(),
            'reference' => $booking->reference,
            'version' => $this->version($booking),
            'expires_at' => $this->expiry($booking),
        ];
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedPayload, $this->signingKey());
        $token = $encodedPayload.'.'.$signature;

        $queryParameters = [];
        $queryPosition = strpos($baseUrl, '?');
        $urlPath = $queryPosition === false ? $baseUrl : substr($baseUrl, 0, $queryPosition);
        if ($queryPosition !== false) {
            parse_str(substr($baseUrl, $queryPosition + 1), $queryParameters);
        }
        unset($queryParameters['management_token']);
        $queryParameters['business'] = $business->slug;
        $queryParameters['reference'] = $booking->reference;
        $query = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);

        return $urlPath.'?'.$query.'#management_token='.rawurlencode($token);
    }

    public function validationError(string $token, Business $business, Booking $booking): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
            return 'BOOKING_MANAGEMENT_LINK_INVALID';
        }

        $expectedSignature = hash_hmac('sha256', $parts[0], $this->signingKey());
        if (! hash_equals($expectedSignature, $parts[1])) {
            return 'BOOKING_MANAGEMENT_LINK_INVALID';
        }

        try {
            $payloadJson = $this->base64UrlDecode($parts[0]);
            $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'BOOKING_MANAGEMENT_LINK_INVALID';
        }

        if (! is_array($payload)
            || ($payload['scope'] ?? null) !== self::TOKEN_SCOPE
            || ($payload['business_id'] ?? null) !== (string) $business->getKey()
            || ($payload['booking_id'] ?? null) !== (string) $booking->getKey()
            || ($payload['reference'] ?? null) !== $booking->reference
            || ! is_int($payload['version'] ?? null)
            || ! is_string($payload['expires_at'] ?? null)) {
            return 'BOOKING_MANAGEMENT_LINK_INVALID';
        }

        if ($payload['version'] !== $this->version($booking) || $payload['expires_at'] !== $this->expiry($booking)) {
            return 'BOOKING_MANAGEMENT_LINK_REVOKED';
        }

        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true)) {
            return 'BOOKING_MANAGEMENT_LINK_REVOKED';
        }

        if (now()->greaterThanOrEqualTo($booking->ends_at)) {
            return 'BOOKING_MANAGEMENT_LINK_EXPIRED';
        }

        return null;
    }

    public static function isValidManagementUrl(mixed $url): bool
    {
        if (! is_string($url) || trim($url) !== $url || $url === '' || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return false;
        }

        if (strtolower($parts['scheme']) === 'https') {
            return true;
        }

        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || in_array($host, ['127.0.0.1', '::1'], true);
    }

    private function expiry(Booking $booking): string
    {
        return $booking->ends_at
            ->setTimezone('UTC')
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private function version(Booking $booking): int
    {
        return max(0, (int) data_get($booking->metadata, 'booking_management_version', 0));
    }

    private function signingKey(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new LogicException('APP_KEY must be configured to sign booking management links.');
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new JsonException('Invalid token encoding.');
        }

        return $decoded;
    }
}
