<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PeriodComparisonWindow
{
    private function __construct(
        public CarbonImmutable $currentStart,
        public CarbonImmutable $currentEnd,
        public CarbonImmutable $previousStart,
        public CarbonImmutable $previousEnd,
        public bool $currentPartial,
        public bool $previousCapped,
        public bool $comparable,
        public bool $chartable,
        public bool $custom,
        public int $currentDays,
        public int $previousDays,
    ) {}

    public static function fromFilters(
        string $period,
        ?string $startDate,
        ?string $endDate,
        CarbonImmutable $now,
    ): self {
        if ($period === 'custom') {
            if ($startDate === null || $endDate === null) {
                throw new InvalidArgumentException('Custom comparison dates are required.');
            }

            [$currentStart, $currentEnd] = ReportRange::custom($startDate, $endDate)->bounds($now);
            $days = self::calendarDays($currentStart, $currentEnd);
            $previousStart = $currentStart->subDays($days);

            return self::make(
                $currentStart,
                $currentEnd,
                $previousStart,
                $currentStart,
                currentPartial: false,
                previousCapped: false,
                comparable: true,
                custom: true,
            );
        }

        $reportPeriod = ReportPeriod::tryFrom($period);
        if ($reportPeriod === null) {
            throw new InvalidArgumentException('Unsupported comparison period.');
        }

        [$periodStart, $periodEnd] = ReportRange::preset($reportPeriod)->bounds($now);
        $currentEnd = $now->lessThan($periodEnd) ? $now : $periodEnd;
        $previousStart = match ($reportPeriod) {
            ReportPeriod::Today => $periodStart->subDay(),
            ReportPeriod::CurrentWeek => $periodStart->subWeek(),
            ReportPeriod::CurrentMonth => $periodStart->subMonth(),
        };
        $elapsed = self::elapsedMicroseconds($periodStart, $currentEnd);
        $targetPreviousEnd = $previousStart->addMicroseconds($elapsed);
        $previousCapped = $targetPreviousEnd->greaterThan($periodStart);
        $previousEnd = $previousCapped ? $periodStart : $targetPreviousEnd;
        $comparable = $elapsed === self::elapsedMicroseconds($previousStart, $previousEnd);

        return self::make(
            $periodStart,
            $currentEnd,
            $previousStart,
            $previousEnd,
            currentPartial: $currentEnd->lessThan($periodEnd),
            previousCapped: $previousCapped,
            comparable: $comparable,
            custom: false,
        );
    }

    private static function make(
        CarbonImmutable $currentStart,
        CarbonImmutable $currentEnd,
        CarbonImmutable $previousStart,
        CarbonImmutable $previousEnd,
        bool $currentPartial,
        bool $previousCapped,
        bool $comparable,
        bool $custom,
    ): self {
        $currentDays = self::calendarDays($currentStart, $currentEnd);
        $previousDays = self::calendarDays($previousStart, $previousEnd);
        $chartable = $comparable
            && $currentDays > 0
            && $currentDays === $previousDays
            && $currentDays <= 366;

        return new self(
            $currentStart,
            $currentEnd,
            $previousStart,
            $previousEnd,
            $currentPartial,
            $previousCapped,
            $comparable,
            $chartable,
            $custom,
            $currentDays,
            $previousDays,
        );
    }

    private static function elapsedMicroseconds(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (($end->getTimestamp() - $start->getTimestamp()) * 1_000_000)
            + ((int) $end->format('u') - (int) $start->format('u'));
    }

    private static function calendarDays(CarbonImmutable $start, CarbonImmutable $end): int
    {
        if (! $end->greaterThan($start)) {
            return 0;
        }

        $startDate = CarbonImmutable::parse($start->toDateString(), 'UTC');
        $endDate = CarbonImmutable::parse($end->toDateString(), 'UTC');
        $days = (int) $startDate->diffInDays($endDate);

        return $days + (int) $end->greaterThan($end->startOfDay());
    }
}
