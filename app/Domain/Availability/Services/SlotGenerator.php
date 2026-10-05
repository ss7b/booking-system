<?php

namespace App\Domain\Availability\Services;

use App\Domain\Availability\ValueObjects\TimeRange;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class SlotGenerator
{
    /**
     * @param list<TimeRange> $workingWindows
     * @param list<TimeRange> $busyIntervals
     * @return list<TimeRange>
     */
    public function generate(
        array $workingWindows,
        array $busyIntervals,
        int $durationMinutes,
        int $slotIntervalMinutes,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0,
        ?DateTimeImmutable $notBefore = null,
        ?DateTimeImmutable $notAfter = null,
    ): array {
        if ($durationMinutes <= 0 || $slotIntervalMinutes <= 0 || $bufferBeforeMinutes < 0 || $bufferAfterMinutes < 0) {
            throw new InvalidArgumentException('Duration and interval must be positive; buffers cannot be negative.');
        }

        $slots = [];
        $duration = new DateInterval('PT'.$durationMinutes.'M');
        $interval = new DateInterval('PT'.$slotIntervalMinutes.'M');
        $beforeBuffer = new DateInterval('PT'.$bufferBeforeMinutes.'M');
        $afterBuffer = new DateInterval('PT'.$bufferAfterMinutes.'M');

        foreach ($workingWindows as $window) {
            for ($start = $window->start; $start->add($duration) <= $window->end; $start = $start->add($interval)) {
                if (($notBefore !== null && $start < $notBefore) || ($notAfter !== null && $start > $notAfter)) {
                    continue;
                }

                $end = $start->add($duration);
                $occupied = new TimeRange($start->sub($beforeBuffer), $end->add($afterBuffer));

                foreach ($busyIntervals as $busyInterval) {
                    if ($occupied->overlaps($busyInterval)) {
                        continue 2;
                    }
                }

                $slots[$start->format('U.u')] = new TimeRange($start, $end);
            }
        }

        $slots = array_values($slots);
        usort($slots, static fn (TimeRange $left, TimeRange $right): int => $left->start <=> $right->start);

        return $slots;
    }
}