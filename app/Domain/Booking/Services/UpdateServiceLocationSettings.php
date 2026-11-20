<?php

namespace App\Domain\Booking\Services;

use App\Domain\Availability\Services\CapacityEvaluator;
use App\Domain\Availability\ValueObjects\TimeRange;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceLocation;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class UpdateServiceLocationSettings
{
    public function __construct(
        private readonly BookingLockManager $locks,
        private readonly CapacityEvaluator $capacity,
    ) {}

    public function execute(
        Business $business,
        Service $service,
        Location $location,
        BookingMode $mode,
        int $capacity,
    ): ServiceLocation {
        return DB::transaction(
            fn (): ServiceLocation => $this->apply($business, $service, [[
                'location_id' => (string) $location->getKey(),
                'booking_mode' => $mode,
                'capacity' => $capacity,
            ]], false, false)[0],
            attempts: 3,
        );
    }

    /**
     * @param  list<array{location_id: string, booking_mode: string|BookingMode, capacity: int|string}>  $settings
     * @return list<ServiceLocation>
     */
    public function executeMany(Business $business, Service $service, array $settings): array
    {
        return DB::transaction(
            fn (): array => $this->apply($business, $service, $settings, true, true),
            attempts: 3,
        );
    }

    /**
     * @param  list<array{location_id: string, booking_mode: string|BookingMode, capacity: int|string}>  $settings
     * @return list<ServiceLocation>
     */
    public function executeManyWithinTransaction(Business $business, Service $service, array $settings): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Service-location settings must be saved inside a database transaction.');
        }

        return $this->apply($business, $service, $settings, true, true);
    }

    /**
     * @param  list<array{location_id: string, booking_mode: string|BookingMode, capacity: int|string}>  $settings
     * @return list<ServiceLocation>
     */
    private function apply(
        Business $business,
        Service $service,
        array $settings,
        bool $removeMissing,
        bool $allowCreate,
    ): array {
        $normalized = [];
        foreach ($settings as $setting) {
            $locationId = (string) ($setting['location_id'] ?? '');
            $rawMode = $setting['booking_mode'] ?? null;
            $mode = $rawMode instanceof BookingMode ? $rawMode : BookingMode::tryFrom((string) $rawMode);
            $rawCapacity = $setting['capacity'] ?? null;
            $capacity = filter_var($rawCapacity, FILTER_VALIDATE_INT);

            if ($locationId === '' || $mode === null) {
                throw new DomainException('BOOKING_RELATIONSHIP_INVALID');
            }

            if ($capacity === false || $capacity < 1) {
                throw new DomainException('CAPACITY_MUST_BE_POSITIVE');
            }

            if (isset($normalized[$locationId])) {
                throw new DomainException('DUPLICATE_SERVICE_LOCATION');
            }

            $normalized[$locationId] = [
                'booking_mode' => $mode,
                'capacity' => $capacity,
            ];
        }

        Business::query()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();
        $service = Service::query()
            ->whereKey($service->getKey())
            ->where('business_id', $business->getKey())
            ->lockForUpdate()
            ->first();

        if (! $service) {
            throw new DomainException('BOOKING_RELATIONSHIP_INVALID');
        }

        $locationIds = array_keys($normalized);
        $locations = Location::query()
            ->where('business_id', $business->getKey())
            ->whereIn('id', $locationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Location $location): string => (string) $location->getKey());

        if ($locations->count() !== count($locationIds)) {
            throw new DomainException('BOOKING_RELATIONSHIP_INVALID');
        }

        $existingQuery = ServiceLocation::query()->where('service_id', $service->getKey());
        if (! $removeMissing) {
            $existingQuery->whereIn('location_id', $locationIds);
        }

        $existingRows = $existingQuery->orderBy('location_id')->get();
        $existing = $existingRows->isEmpty()
            ? []
            : collect($this->locks->lockForServiceLocations(
                $business,
                $existingRows->map(fn (ServiceLocation $assignment): array => [
                    (string) $assignment->service_id,
                    (string) $assignment->location_id,
                ])->all(),
            ))->keyBy(fn (ServiceLocation $assignment): string => (string) $assignment->location_id)->all();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($removeMissing) {
            foreach ($existing as $locationId => $assignment) {
                if (isset($normalized[$locationId])) {
                    continue;
                }

                if ($this->hasActiveBookings($business, $service, $locationId)) {
                    throw new DomainException('LOCATION_REMOVAL_BLOCKED');
                }
            }
        }

        foreach ($normalized as $locationId => $settingsForLocation) {
            $assignment = $existing[$locationId] ?? null;
            if (! $assignment) {
                if (! $allowCreate) {
                    throw new DomainException('BOOKING_RELATIONSHIP_INVALID');
                }

                continue;
            }

            $mode = $settingsForLocation['booking_mode'];
            $capacity = $settingsForLocation['capacity'];
            $modeChanged = $assignment->booking_mode !== $mode;

            if ($modeChanged && $this->hasActiveBookings($business, $service, $locationId)) {
                throw new DomainException('BOOKING_MODE_CHANGE_BLOCKED');
            }

            if ($mode === BookingMode::Staffless && $capacity < (int) $assignment->capacity) {
                $ranges = $this->capacity->activeBookingRanges(
                    $business,
                    $locations->get($locationId),
                    $service,
                    $now,
                    new DateTimeImmutable('9999-12-31 23:59:59', new DateTimeZone('UTC')),
                    forUpdate: true,
                );
                $futureRanges = [];
                foreach ($ranges as $range) {
                    if ($range->end <= $now) {
                        continue;
                    }

                    $futureRanges[] = new TimeRange(
                        $range->start > $now ? $range->start : $now,
                        $range->end,
                    );
                }

                if ($this->capacity->peakOccupancy($futureRanges) > $capacity) {
                    throw new DomainException('CAPACITY_BELOW_CURRENT_OCCUPANCY');
                }
            }
        }

        $saved = [];
        foreach ($normalized as $locationId => $settingsForLocation) {
            $assignment = $existing[$locationId] ?? new ServiceLocation([
                'service_id' => $service->getKey(),
                'location_id' => $locationId,
            ]);
            $assignment->booking_mode = $settingsForLocation['booking_mode'];
            $assignment->capacity = $settingsForLocation['capacity'];
            $assignment->save();
            $saved[] = $assignment;
        }

        if ($removeMissing) {
            foreach ($existing as $locationId => $assignment) {
                if (! isset($normalized[$locationId])) {
                    $assignment->delete();
                }
            }
        }

        return $saved;
    }

    private function hasActiveBookings(Business $business, Service $service, string $locationId): bool
    {
        return Booking::query()
            ->where('business_id', $business->getKey())
            ->where('service_id', $service->getKey())
            ->where('location_id', $locationId)
            ->where('occupied_ends_at', '>', now())
            ->where(function ($query): void {
                $query->whereIn('status', [
                    BookingStatus::Confirmed->value,
                    BookingStatus::CheckedIn->value,
                ])->orWhere(function ($held): void {
                    $held->where('status', BookingStatus::Held->value)
                        ->where('hold_expires_at', '>', now());
                });
            })
            ->lockForUpdate()
            ->exists();
    }
}
