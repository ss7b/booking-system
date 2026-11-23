<?php

namespace Tests\Unit;

use App\Domain\Reports\PeriodComparisonWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PeriodComparisonWindowTest extends TestCase
{
    public function test_today_and_week_compare_equal_elapsed_local_ranges(): void
    {
        $now = CarbonImmutable::parse('2026-11-23 11:30:45.123456', 'Asia/Riyadh');

        $today = PeriodComparisonWindow::fromFilters('today', null, null, $now);
        $this->assertSame('2026-11-23T00:00:00+03:00', $today->currentStart->toIso8601String());
        $this->assertSame($now->toIso8601String(), $today->currentEnd->toIso8601String());
        $this->assertSame('2026-11-22T00:00:00+03:00', $today->previousStart->toIso8601String());
        $this->assertSame('2026-11-22T11:30:45+03:00', $today->previousEnd->toIso8601String());
        $this->assertTrue($today->currentPartial);
        $this->assertTrue($today->comparable);
        $this->assertTrue($today->chartable);

        $week = PeriodComparisonWindow::fromFilters('current_week', null, null, $now);
        $this->assertSame('2026-11-22T00:00:00+03:00', $week->currentStart->toIso8601String());
        $this->assertSame('2026-11-15T00:00:00+03:00', $week->previousStart->toIso8601String());
        $this->assertSame('2026-11-16T11:30:45+03:00', $week->previousEnd->toIso8601String());
        $this->assertTrue($week->comparable);
    }

    public function test_month_compares_elapsed_time_and_caps_at_the_prior_month_boundary(): void
    {
        $november = PeriodComparisonWindow::fromFilters(
            'current_month',
            null,
            null,
            CarbonImmutable::parse('2026-11-23 12:00:00', 'Asia/Riyadh'),
        );
        $this->assertSame('2026-10-01T00:00:00+03:00', $november->previousStart->toIso8601String());
        $this->assertSame('2026-10-23T12:00:00+03:00', $november->previousEnd->toIso8601String());
        $this->assertFalse($november->previousCapped);
        $this->assertTrue($november->comparable);

        $leapMarch = PeriodComparisonWindow::fromFilters(
            'current_month',
            null,
            null,
            CarbonImmutable::parse('2024-03-31 16:00:00', 'Asia/Riyadh'),
        );
        $this->assertSame('2024-02-01T00:00:00+03:00', $leapMarch->previousStart->toIso8601String());
        $this->assertSame('2024-03-01T00:00:00+03:00', $leapMarch->previousEnd->toIso8601String());
        $this->assertTrue($leapMarch->previousCapped);
        $this->assertFalse($leapMarch->comparable);
        $this->assertFalse($leapMarch->chartable);
    }

    public function test_custom_range_uses_equal_local_calendar_days_across_dst(): void
    {
        $window = PeriodComparisonWindow::fromFilters(
            'custom',
            '2026-03-07',
            '2026-03-10',
            CarbonImmutable::parse('2026-03-20 12:00:00', 'America/Los_Angeles'),
        );

        $this->assertSame('2026-03-07T00:00:00-08:00', $window->currentStart->toIso8601String());
        $this->assertSame('2026-03-11T00:00:00-07:00', $window->currentEnd->toIso8601String());
        $this->assertSame('2026-03-03T00:00:00-08:00', $window->previousStart->toIso8601String());
        $this->assertSame('2026-03-07T00:00:00-08:00', $window->previousEnd->toIso8601String());
        $this->assertSame(4, $window->currentDays);
        $this->assertSame(4, $window->previousDays);
        $this->assertTrue($window->comparable);
        $this->assertTrue($window->chartable);
    }

    public function test_day_bounds_keep_the_same_elapsed_duration_across_a_dst_transition(): void
    {
        $window = PeriodComparisonWindow::fromFilters(
            'today',
            null,
            null,
            CarbonImmutable::parse('2026-03-08 12:00:00', 'America/Los_Angeles'),
        );

        $this->assertSame('2026-03-08T00:00:00-08:00', $window->currentStart->toIso8601String());
        $this->assertSame('2026-03-07T11:00:00-08:00', $window->previousEnd->toIso8601String());
        $this->assertTrue($window->comparable);
        $this->assertTrue($window->chartable);
    }
}
