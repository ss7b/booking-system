<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportRange
{
    private function __construct(
        private ?ReportPeriod $period,
        private ?string $from = null,
        private ?string $to = null,
    ) {}

    public static function preset(ReportPeriod $period): self
    {
        return new self($period);
    }

    public static function custom(string $from, string $to): self
    {
        if (! self::isBookingDate($from) || ! self::isBookingDate($to) || $from > $to || $to === '9999-12-31') {
            throw new InvalidArgumentException('A valid ordered range of booking dates is required.');
        }

        return new self(null, $from, $to);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function bounds(CarbonImmutable $now): array
    {
        if ($this->period === null) {
            $timezone = $now->getTimezone();
            $exclusiveEnd = CarbonImmutable::parse($this->to, 'UTC')->addDay()->toDateString();

            return [
                CarbonImmutable::parse($this->from, $timezone)->startOfDay(),
                CarbonImmutable::parse($exclusiveEnd, $timezone)->startOfDay(),
            ];
        }

        $start = match ($this->period) {
            ReportPeriod::Today => $now->startOfDay(),
            ReportPeriod::CurrentWeek => $now->startOfWeek(CarbonImmutable::SUNDAY),
            ReportPeriod::CurrentMonth => $now->startOfMonth(),
        };
        $end = match ($this->period) {
            ReportPeriod::Today => $start->addDay()->startOfDay(),
            ReportPeriod::CurrentWeek => $start->addWeek()->startOfDay(),
            ReportPeriod::CurrentMonth => $start->addMonth()->startOfDay(),
        };

        return [$start, $end];
    }

    private static function isBookingDate(string $date): bool
    {
        return preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) === 1
            && (int) $parts[1] >= 1000
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
