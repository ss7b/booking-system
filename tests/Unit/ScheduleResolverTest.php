<?php

namespace Tests\Unit;

use App\Domain\Scheduling\Services\ScheduleResolver;
use PHPUnit\Framework\TestCase;

class ScheduleResolverTest extends TestCase
{
    public function test_resolves_weekly_windows_and_uses_closed_date_override(): void
    {
        $resolver = new ScheduleResolver;
        $rules = [
            ['day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '13:00:00'],
            ['day_of_week' => 1, 'start_time' => '14:00:00', 'end_time' => '17:00:00'],
        ];

        $windows = $resolver->resolve('2026-10-05', 'Asia/Riyadh', $rules, []);
        $closed = $resolver->resolve('2026-10-05', 'Asia/Riyadh', $rules, [
            ['date' => '2026-10-05', 'mode' => 'closed', 'start_time' => null, 'end_time' => null],
        ]);

        $this->assertSame(['09:00', '14:00'], array_map(static fn ($window) => $window->start->format('H:i'), $windows));
        $this->assertSame('Asia/Riyadh', $windows[0]->start->getTimezone()->getName());
        $this->assertSame([], $closed);
    }

    public function test_open_date_override_replaces_weekly_windows(): void
    {
        $windows = (new ScheduleResolver)->resolve(
            '2026-10-05',
            'Asia/Riyadh',
            [['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
            [['date' => '2026-10-05', 'mode' => 'open', 'start_time' => '11:00', 'end_time' => '13:00']],
        );

        $this->assertSame('11:00', $windows[0]->start->format('H:i'));
        $this->assertSame('13:00', $windows[0]->end->format('H:i'));
    }
}