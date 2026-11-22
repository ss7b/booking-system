<?php

namespace App\Domain\Availability\Services;

use App\Domain\Availability\ValueObjects\TimeRange;
use App\Domain\Scheduling\Services\ScheduleResolver;
use App\Enums\BookingMode;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceLocation;
use App\Models\Staff;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class AvailabilityService
{
    /** @var array<string, mixed> */
    private array $assignmentCache = [];

    /** @var array<string, mixed> */
    private array $staffCache = [];

    /** @var list<Booking>|null */
    private ?array $batchBookingRanges = null;

    /** @var list<object>|null */
    private ?array $batchStaffBlocks = null;

    /** @var list<TimeRange>|null */
    private ?array $batchStafflessRanges = null;

    public function __construct(
        private readonly ScheduleResolver $scheduleResolver,
        private readonly SlotGenerator $slotGenerator,
        private readonly CapacityEvaluator $capacityEvaluator,
    ) {}

    /**
     * @return list<array{date:string,available:bool}>
     */
    public function forDateRange(
        Business $business,
        Location $location,
        Service $service,
        string $from,
        string $until,
        ?Staff $requestedStaff = null,
        ?string $ignoreBookingId = null,
        ?ServiceLocation $assignment = null,
    ): array {
        $this->assignmentCache = [];
        $this->staffCache = [];
        $this->batchBookingRanges = null;
        $this->batchStaffBlocks = null;
        $this->batchStafflessRanges = null;

        $timezone = $location->timezone ?: $business->timezone;
        $zone = new DateTimeZone($timezone);
        $rangeStart = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
        $rangeEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $until, $zone);

        if (! $rangeStart || ! $rangeEnd || $rangeStart > $rangeEnd || $rangeStart->diff($rangeEnd)->days > 30) {
            throw new \InvalidArgumentException('Date range must be valid and contain at most 31 days.');
        }

        $assignment ??= $this->assignment($service, $location);
        if (! $assignment) {
            throw new \DomainException('BOOKING_RELATIONSHIP_INVALID');
        }

        $utcRangeStart = $rangeStart->setTimezone(new DateTimeZone('UTC'))->modify('-'.(int) $service->buffer_before_minutes.' minutes');
        $utcRangeEnd = $rangeEnd->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->modify('+'.((int) $service->duration_minutes + (int) $service->buffer_after_minutes).' minutes');

        if ($assignment->booking_mode === BookingMode::Staffless) {
            $this->batchStafflessRanges = $this->capacityEvaluator->activeBookingRanges(
                $business,
                $location,
                $service,
                $utcRangeStart,
                $utcRangeEnd,
                $ignoreBookingId,
                DB::transactionLevel() > 0,
            );
        } else {
            $staffMembers = $this->staffMembers($business, $location, $service, $requestedStaff);

            if ($staffMembers->isNotEmpty()) {
                $staffIds = $staffMembers->modelKeys();

                $this->batchStaffBlocks = DB::table('staff_blocks')
                    ->whereIn('staff_id', $staffIds)
                    ->where('starts_at', '<', $utcRangeEnd)
                    ->where('ends_at', '>', $utcRangeStart)
                    ->get(['staff_id', 'starts_at', 'ends_at'])
                    ->map(static fn (object $block): object => (object) [
                        'staff_id' => (string) $block->staff_id,
                        'starts_at' => new DateTimeImmutable((string) $block->starts_at),
                        'ends_at' => new DateTimeImmutable((string) $block->ends_at),
                    ])
                    ->all();

                $this->batchBookingRanges = Booking::query()
                    ->whereIn('staff_id', $staffIds)
                    ->whereIn('status', ['held', 'confirmed', 'checked_in'])
                    ->where(fn ($query) => $query->where('status', '!=', 'held')->orWhere('hold_expires_at', '>', now()))
                    ->when($ignoreBookingId, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
                    ->where('occupied_starts_at', '<', $utcRangeEnd)
                    ->where('occupied_ends_at', '>', $utcRangeStart)
                    ->get(['staff_id', 'occupied_starts_at', 'occupied_ends_at'])
                    ->all();
            }
        }

        try {
            $dates = [];
            for ($date = $rangeStart; $date <= $rangeEnd; $date = $date->modify('+1 day')) {
                $dateString = $date->format('Y-m-d');
                $result = $this->forDate(
                    $business,
                    $location,
                    $service,
                    $dateString,
                    $requestedStaff,
                    $ignoreBookingId,
                    $assignment,
                );
                $dates[] = ['date' => $dateString, 'available' => $result['slots'] !== []];
            }

            return $dates;
        } finally {
            $this->batchBookingRanges = null;
            $this->batchStaffBlocks = null;
            $this->batchStafflessRanges = null;
            $this->assignmentCache = [];
            $this->staffCache = [];
        }
    }

    /** @return array{date:string,timezone:string,service:array<string,int>,staff:?array<string,string>,slots:array} */
    public function forDate(
        Business $business,
        Location $location,
        Service $service,
        string $date,
        ?Staff $requestedStaff = null,
        ?string $ignoreBookingId = null,
        ?ServiceLocation $assignment = null,
    ): array {
        $timezone = $location->timezone ?: $business->timezone;
        $zone = new DateTimeZone($timezone);
        $localStart = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if (! $localStart || $localStart->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Date must be a valid YYYY-MM-DD value.');
        }

        $assignment ??= $this->assignment($service, $location);

        if (! $assignment) {
            throw new \DomainException('BOOKING_RELATIONSHIP_INVALID');
        }

        if ($assignment->booking_mode === BookingMode::Staffless) {
            if ($requestedStaff !== null) {
                throw new \InvalidArgumentException('Staff cannot be selected for a staffless service.');
            }

            return $this->stafflessForDate(
                $business,
                $location,
                $service,
                $assignment,
                $date,
                $timezone,
                $zone,
                $localStart,
                $ignoreBookingId,
            );
        }

        $localEnd = $localStart->modify('+1 day');
        $utcStart = $localStart->setTimezone(new DateTimeZone('UTC'));
        $utcEnd = $localEnd->setTimezone(new DateTimeZone('UTC'));
        $busyRangeStart = $utcStart->modify('-'.(int) $service->buffer_before_minutes.' minutes');
        $busyRangeEnd = $utcEnd->modify('+'.(int) $service->buffer_after_minutes.' minutes');

        $staffMembers = $this->staffMembers($business, $location, $service, $requestedStaff);

        $location->loadMissing('calendar.rules', 'calendar.overrides');
        $slots = [];
        $slotOptions = [];

        foreach ($staffMembers as $staff) {
            $calendar = $staff->calendar ?? $location->calendar;

            if (! $calendar) {
                continue;
            }

            $calendar->loadMissing('rules', 'overrides');
            $windows = $this->scheduleResolver->resolve(
                $date,
                $calendar->timezone ?: $timezone,
                $calendar->rules->map(fn ($rule) => [
                    'day_of_week' => (int) $rule->day_of_week,
                    'start_time' => (string) $rule->start_time,
                    'end_time' => (string) $rule->end_time,
                ])->all(),
                $calendar->overrides->map(fn ($override) => [
                    'date' => $override->date->format('Y-m-d'),
                    'mode' => $override->mode,
                    'start_time' => $override->start_time,
                    'end_time' => $override->end_time,
                ])->all(),
            );

            if ($windows === []) {
                continue;
            }

            $busy = [];

            if ($this->batchStaffBlocks !== null) {
                foreach ($this->batchStaffBlocks as $block) {
                    if ((string) $block->staff_id !== (string) $staff->getKey()) {
                        continue;
                    }

                    $blockStart = DateTimeImmutable::createFromInterface($block->starts_at);
                    $blockEnd = DateTimeImmutable::createFromInterface($block->ends_at);
                    if ($blockStart < $busyRangeEnd && $blockEnd > $busyRangeStart) {
                        $busy[] = new TimeRange($blockStart, $blockEnd);
                    }
                }
            } else {
                $busy = $staff->blocks()
                    ->where('starts_at', '<', $busyRangeEnd)
                    ->where('ends_at', '>', $busyRangeStart)
                    ->get(['starts_at', 'ends_at'])
                    ->map(fn ($block) => new TimeRange($block->starts_at, $block->ends_at))
                    ->all();
            }

            if ($this->batchBookingRanges !== null) {
                foreach ($this->batchBookingRanges as $booking) {
                    if ((string) $booking->staff_id !== (string) $staff->getKey()) {
                        continue;
                    }

                    $range = new TimeRange($booking->occupied_starts_at, $booking->occupied_ends_at);
                    if ($range->start < $busyRangeEnd && $range->end > $busyRangeStart) {
                        $busy[] = $range;
                    }
                }
            } else {
                $busyBookings = Booking::query()
                    ->where('staff_id', $staff->getKey())
                    ->whereIn('status', ['held', 'confirmed', 'checked_in'])
                    ->where(fn ($query) => $query->where('status', '!=', 'held')->orWhere('hold_expires_at', '>', now()))
                    ->when($ignoreBookingId, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
                    ->where('occupied_starts_at', '<', $busyRangeEnd)
                    ->where('occupied_ends_at', '>', $busyRangeStart)
                    ->get(['occupied_starts_at', 'occupied_ends_at']);

                foreach ($busyBookings as $booking) {
                    $busy[] = new TimeRange($booking->occupied_starts_at, $booking->occupied_ends_at);
                }
            }

            $minimumStart = (new DateTimeImmutable('now', $zone))->modify('+'.$service->min_notice_minutes.' minutes');
            $maximumStart = $service->max_advance_days === null
                ? null
                : (new DateTimeImmutable('now', $zone))->modify('+'.$service->max_advance_days.' days');

            foreach ($this->slotGenerator->generateOptions(
                $windows,
                $busy,
                (int) $service->duration_minutes,
                (int) $service->slot_interval_minutes,
                (int) $service->buffer_before_minutes,
                (int) $service->buffer_after_minutes,
                $minimumStart,
                $maximumStart,
            ) as $option) {
                $slotData = [
                    'staff_id' => $staff->getKey(),
                    'start' => $option['range']->start->format(DATE_ATOM),
                    'end' => $option['range']->end->format(DATE_ATOM),
                ];

                $slotOptions[] = $slotData + ['available' => $option['available']];

                if ($option['available']) {
                    $slots[] = $slotData;
                }
            }
        }

        usort($slots, static fn (array $left, array $right): int => [$left['start'], $left['staff_id']] <=> [$right['start'], $right['staff_id']]);

        return [
            'date' => $date,
            'timezone' => $timezone,
            'service' => [
                'id' => $service->getKey(),
                'duration_minutes' => (int) $service->duration_minutes,
                'slot_interval_minutes' => (int) $service->slot_interval_minutes,
                'booking_mode' => $assignment->booking_mode->value,
            ],
            'staff' => $requestedStaff ? ['id' => $requestedStaff->getKey(), 'name' => $requestedStaff->name] : null,
            'slots' => array_map(static function (array $slot) use ($requestedStaff): array {
                if ($requestedStaff) {
                    unset($slot['staff_id']);
                }

                return $slot;
            }, $slots),
            'slot_options' => array_map(static function (array $slot) use ($requestedStaff): array {
                if ($requestedStaff) {
                    unset($slot['staff_id']);
                }

                return $slot;
            }, $slotOptions),
        ];
    }

    private function stafflessForDate(
        Business $business,
        Location $location,
        Service $service,
        ServiceLocation $assignment,
        string $date,
        string $timezone,
        DateTimeZone $zone,
        DateTimeImmutable $localStart,
        ?string $ignoreBookingId,
    ): array {
        $location->loadMissing('calendar.rules', 'calendar.overrides');
        $calendar = $location->calendar;
        if (! $calendar) {
            return $this->emptyStafflessAvailability($date, $timezone, $service, $assignment);
        }

        $calendar->loadMissing('rules', 'overrides');
        $windows = $this->scheduleResolver->resolve(
            $date,
            $calendar->timezone ?: $timezone,
            $calendar->rules->map(fn ($rule) => [
                'day_of_week' => (int) $rule->day_of_week,
                'start_time' => (string) $rule->start_time,
                'end_time' => (string) $rule->end_time,
            ])->all(),
            $calendar->overrides->map(fn ($override) => [
                'date' => $override->date->format('Y-m-d'),
                'mode' => $override->mode,
                'start_time' => $override->start_time,
                'end_time' => $override->end_time,
            ])->all(),
        );

        if ($windows === []) {
            return $this->emptyStafflessAvailability($date, $timezone, $service, $assignment);
        }

        $utc = new DateTimeZone('UTC');
        $windowStart = $windows[0]->start->modify('-'.(int) $service->buffer_before_minutes.' minutes')->setTimezone($utc);
        $lastWindow = $windows[array_key_last($windows)];
        $windowEnd = $lastWindow->end
            ->modify('+'.((int) $service->duration_minutes + (int) $service->buffer_after_minutes).' minutes')
            ->setTimezone($utc);
        $busy = $this->batchStafflessRanges !== null
            ? array_values(array_filter(
                $this->batchStafflessRanges,
                static fn (TimeRange $range): bool => $range->start < $windowEnd && $range->end > $windowStart,
            ))
            : $this->capacityEvaluator->activeBookingRanges(
                $business,
                $location,
                $service,
                $windowStart,
                $windowEnd,
                $ignoreBookingId,
                DB::transactionLevel() > 0,
            );

        $minimumStart = (new DateTimeImmutable('now', $zone))->modify('+'.$service->min_notice_minutes.' minutes');
        $maximumStart = $service->max_advance_days === null
            ? null
            : (new DateTimeImmutable('now', $zone))->modify('+'.$service->max_advance_days.' days');
        $slots = [];
        $slotOptions = [];

        foreach ($this->slotGenerator->generateOptions(
            $windows,
            [],
            (int) $service->duration_minutes,
            (int) $service->slot_interval_minutes,
            (int) $service->buffer_before_minutes,
            (int) $service->buffer_after_minutes,
            $minimumStart,
            $maximumStart,
        ) as $option) {
            $occupied = new TimeRange(
                $option['range']->start->modify('-'.(int) $service->buffer_before_minutes.' minutes'),
                $option['range']->end->modify('+'.(int) $service->buffer_after_minutes.' minutes'),
            );
            $available = $this->capacityEvaluator->allows((int) $assignment->capacity, $occupied, $busy);
            $slot = [
                'staff_id' => null,
                'start' => $option['range']->start->format(DATE_ATOM),
                'end' => $option['range']->end->format(DATE_ATOM),
            ];
            $slotOptions[] = $slot + ['available' => $available];

            if ($available) {
                $slots[] = $slot;
            }
        }

        return [
            'date' => $date,
            'timezone' => $timezone,
            'service' => [
                'id' => $service->getKey(),
                'duration_minutes' => (int) $service->duration_minutes,
                'slot_interval_minutes' => (int) $service->slot_interval_minutes,
                'booking_mode' => $assignment->booking_mode->value,
            ],
            'staff' => null,
            'slots' => $slots,
            'slot_options' => $slotOptions,
        ];
    }

    private function emptyStafflessAvailability(
        string $date,
        string $timezone,
        Service $service,
        ServiceLocation $assignment,
    ): array {
        return [
            'date' => $date,
            'timezone' => $timezone,
            'service' => [
                'id' => $service->getKey(),
                'duration_minutes' => (int) $service->duration_minutes,
                'slot_interval_minutes' => (int) $service->slot_interval_minutes,
                'booking_mode' => $assignment->booking_mode->value,
            ],
            'staff' => null,
            'slots' => [],
            'slot_options' => [],
        ];
    }

    private function assignment(Service $service, Location $location): ?ServiceLocation
    {
        $key = $service->getKey().'|'.$location->getKey();
        if (array_key_exists($key, $this->assignmentCache)) {
            return $this->assignmentCache[$key];
        }

        return $this->assignmentCache[$key] = ServiceLocation::query()
            ->where('service_id', $service->getKey())
            ->where('location_id', $location->getKey())
            ->first();
    }

    private function staffMembers(
        Business $business,
        Location $location,
        Service $service,
        ?Staff $requestedStaff,
    ) {
        $key = implode('|', [
            (string) $business->getKey(),
            (string) $location->getKey(),
            (string) $service->getKey(),
            (string) ($requestedStaff?->getKey() ?? ''),
        ]);

        if (array_key_exists($key, $this->staffCache)) {
            return $this->staffCache[$key];
        }

        return $this->staffCache[$key] = Staff::query()
            ->where('business_id', $business->getKey())
            ->where('status', 'active')
            ->whereHas('services', fn ($query) => $query
                ->whereKey($service->getKey())
                ->where('services.business_id', $business->getKey()))
            ->whereHas('locations', fn ($query) => $query
                ->whereKey($location->getKey())
                ->where('locations.business_id', $business->getKey())
                ->where('staff_locations.is_active', true))
            ->when($requestedStaff, fn ($query) => $query->whereKey($requestedStaff->getKey()))
            ->with(['calendar.rules', 'calendar.overrides'])
            ->get();
    }
}
