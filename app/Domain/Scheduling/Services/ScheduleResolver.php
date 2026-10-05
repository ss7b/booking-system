<?php

namespace App\Domain\Scheduling\Services;

use App\Domain\Availability\ValueObjects\TimeRange;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ScheduleResolver
{
    /**
     * @param list<array{day_of_week:int,start_time:string,end_time:string}> $rules
     * @param list<array{date:string,mode:string,start_time:?string,end_time:?string}> $overrides
     * @return list<TimeRange>
     */
    public function resolve(string $date, string $timezone, array $rules, array $overrides): array
    {
        $zone = new DateTimeZone($timezone);
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if (! $day || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Date must be a valid YYYY-MM-DD value.');
        }

        $dateOverrides = array_values(array_filter(
            $overrides,
            static fn (array $override): bool => $override['date'] === $date,
        ));

        $intervals = $dateOverrides === []
            ? array_values(array_filter($rules, static fn (array $rule): bool => $rule['day_of_week'] === (int) $day->format('w')))
            : array_values(array_filter($dateOverrides, static fn (array $override): bool => $override['mode'] === 'open'));

        $windows = [];

        foreach ($intervals as $interval) {
            if (! isset($interval['start_time'], $interval['end_time'])) {
                continue;
            }

            $start = new DateTimeImmutable($date.' '.$interval['start_time'], $zone);
            $end = new DateTimeImmutable($date.' '.$interval['end_time'], $zone);

            if ($end > $start) {
                $windows[] = new TimeRange($start, $end);
            }
        }

        usort($windows, static fn (TimeRange $left, TimeRange $right): int => $left->start <=> $right->start);

        return $windows;
    }
}