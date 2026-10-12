<?php

namespace App\Domain\Booking\Services;

use App\Domain\Availability\Services\AvailabilityService;
use App\Domain\Booking\DTOs\BookingCreationResult;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\Service;
use App\Models\Staff;
use Carbon\Carbon;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class CreateBooking
{
    public function __construct(
        private readonly BookingLockManager $lockManager,
        private readonly BookingConflictChecker $conflicts,
        private readonly BookingReferenceGenerator $referenceGenerator,
        private readonly AvailabilityService $availability,
    ) {}

    public function execute(
        Business $business,
        Location $location,
        Service $service,
        Staff $staff,
        DateTimeImmutable $start,
        array $customerData,
        ?string $notes,
        bool $hold,
        string $source = 'public',
        string $actorType = 'customer',
        ?string $actorId = null,
    ): BookingCreationResult {
        return DB::transaction(function () use ($business, $location, $service, $staff, $start, $customerData, $notes, $hold, $source, $actorType, $actorId): BookingCreationResult {
            if (
                (string) $location->business_id !== (string) $business->getKey()
                || (string) $service->business_id !== (string) $business->getKey()
                || (string) $staff->business_id !== (string) $business->getKey()
                || ! $location->is_active
                || ! $service->is_active
                || $staff->status !== 'active'
                || ! $location->services()->whereKey($service->getKey())->exists()
                || ! $staff->services()->whereKey($service->getKey())->exists()
                || ! $staff->locations()->whereKey($location->getKey())->wherePivot('is_active', true)->exists()
            ) {
                throw new DomainException('BOOKING_RELATIONSHIP_INVALID');
            }

            $timezone = $location->timezone ?: $business->timezone;
            $localStart = $start->setTimezone(new DateTimeZone($timezone));
            $end = $start->add(new DateInterval('PT'.(int) $service->duration_minutes.'M'));
            $occupiedStart = $start->modify('-'.(int) $service->buffer_before_minutes.' minutes');
            $occupiedEnd = $end->modify('+'.(int) $service->buffer_after_minutes.' minutes');

            $this->lockManager->lockForStaffRange(
                $business,
                $staff,
                Carbon::instance($occupiedStart),
                Carbon::instance($occupiedEnd),
                $timezone,
            );
            $this->expireStaleHolds($business, $staff, $localStart->format('Y-m-d'), $timezone);

            if ($this->conflicts->hasConflict($business, $staff, $occupiedStart, $occupiedEnd)) {
                throw new DomainException('SLOT_UNAVAILABLE');
            }

            $availability = $this->availability->forDate($business, $location, $service, $localStart->format('Y-m-d'), $staff);
            $requestedTimestamp = $start->format('U.u');
            $slotIsAvailable = collect($availability['slots'])->contains(
                static fn (array $slot): bool => (new DateTimeImmutable($slot['start']))->format('U.u') === $requestedTimestamp,
            );

            if (! $slotIsAvailable) {
                throw new DomainException('SLOT_UNAVAILABLE');
            }

            $phone = (string) $customerData['phone'];
            $accessToken = Str::random(64);

            $customer = Customer::query()->create([
                'business_id' => $business->getKey(),
                'name' => $customerData['name'],
                'phone' => $phone,
                'normalized_phone' => preg_replace('/\D+/', '', $phone),
                'email' => $customerData['email'] ?? null,
            ]);

            $status = $hold ? BookingStatus::Held : BookingStatus::Confirmed;
            $booking = Booking::query()->create([
                'business_id' => $business->getKey(),
                'location_id' => $location->getKey(),
                'service_id' => $service->getKey(),
                'staff_id' => $staff->getKey(),
                'customer_id' => $customer->getKey(),
                'reference' => $this->referenceGenerator->generate($localStart),
                'status' => $status,
                'source' => $source,
                'starts_at' => $start->setTimezone(new DateTimeZone('UTC')),
                'ends_at' => $end->setTimezone(new DateTimeZone('UTC')),
                'occupied_starts_at' => $occupiedStart->setTimezone(new DateTimeZone('UTC')),
                'occupied_ends_at' => $occupiedEnd->setTimezone(new DateTimeZone('UTC')),
                'hold_expires_at' => $hold ? now()->addMinutes(5) : null,
                'service_name_snapshot' => $service->name,
                'duration_snapshot' => $service->duration_minutes,
                'price_minor_snapshot' => $service->price_minor,
                'currency_snapshot' => $service->currency ?? $business->default_currency,
                'access_token_hash' => Hash::make($accessToken),
                'notes' => $notes,
                'confirmed_at' => $hold ? null : now(),
            ]);

            BookingStatusHistory::query()->create([
                'booking_id' => $booking->getKey(),
                'from_status' => null,
                'to_status' => $status->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
            ]);

            OutboxEvent::query()->create([
                'business_id' => $business->getKey(),
                'event_type' => $hold ? 'BookingHeld' : 'BookingConfirmed',
                'aggregate_type' => 'Booking',
                'aggregate_id' => $booking->getKey(),
                'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
                'occurred_at' => now(),
            ]);

            return new BookingCreationResult($booking, $accessToken);
        }, attempts: 3);
    }

    private function expireStaleHolds(Business $business, Staff $staff, string $date, string $timezone): void
    {
        $localStart = new DateTimeImmutable($date.' 00:00:00', new DateTimeZone($timezone));
        $start = $localStart->setTimezone(new DateTimeZone('UTC'));
        $end = $localStart->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $expired = Booking::query()
            ->where('business_id', $business->getKey())
            ->where('staff_id', $staff->getKey())
            ->where('status', BookingStatus::Held->value)
            ->where('hold_expires_at', '<=', now())
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->get();

        foreach ($expired as $booking) {
            $booking->status = BookingStatus::Expired;
            $booking->save();

            BookingStatusHistory::query()->create([
                'booking_id' => $booking->getKey(),
                'from_status' => BookingStatus::Held->value,
                'to_status' => BookingStatus::Expired->value,
                'actor_type' => 'system',
                'reason' => 'Hold expired during booking availability check.',
            ]);

            OutboxEvent::query()->create([
                'business_id' => $business->getKey(),
                'event_type' => 'BookingExpired',
                'aggregate_type' => 'Booking',
                'aggregate_id' => $booking->getKey(),
                'payload' => ['booking_id' => $booking->getKey(), 'reference' => $booking->reference],
                'occurred_at' => now(),
            ]);
        }
    }
}