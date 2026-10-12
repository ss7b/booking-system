<?php

namespace Tests\Unit;

use App\Domain\Availability\Services\SlotGenerator;
use App\Domain\Availability\ValueObjects\TimeRange;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SlotGeneratorTest extends TestCase
{
    public function test_generates_slots_and_respects_duration_and_busy_intervals(): void
    {
        $generator = new SlotGenerator;
        $slots = $generator->generate(
            [new TimeRange(new DateTimeImmutable('2026-10-05 09:00'), new DateTimeImmutable('2026-10-05 10:30'))],
            [new TimeRange(new DateTimeImmutable('2026-10-05 09:45'), new DateTimeImmutable('2026-10-05 10:00'))],
            durationMinutes: 30,
            slotIntervalMinutes: 15,
        );

        $this->assertSame(
            ['09:00', '09:15', '10:00'],
            array_map(static fn (TimeRange $slot): string => $slot->start->format('H:i'), $slots),
        );
    }

    public function test_buffers_block_adjacent_slots_and_notice_windows_filter_starts(): void
    {
        $generator = new SlotGenerator;
        $slots = $generator->generate(
            [new TimeRange(new DateTimeImmutable('2026-10-05 09:00'), new DateTimeImmutable('2026-10-05 11:00'))],
            [new TimeRange(new DateTimeImmutable('2026-10-05 10:30'), new DateTimeImmutable('2026-10-05 11:00'))],
            durationMinutes: 30,
            slotIntervalMinutes: 30,
            bufferBeforeMinutes: 10,
            bufferAfterMinutes: 10,
            notBefore: new DateTimeImmutable('2026-10-05 09:30'),
            notAfter: new DateTimeImmutable('2026-10-05 10:00'),
        );

        $this->assertSame(['09:30'], array_map(static fn (TimeRange $slot): string => $slot->start->format('H:i'), $slots));
    }

    public function test_generates_available_and_unavailable_slot_options(): void
    {
        $options = (new SlotGenerator)->generateOptions(
            [new TimeRange(new DateTimeImmutable('2026-10-05 09:00'), new DateTimeImmutable('2026-10-05 10:00'))],
            [new TimeRange(new DateTimeImmutable('2026-10-05 09:30'), new DateTimeImmutable('2026-10-05 10:00'))],
            durationMinutes: 30,
            slotIntervalMinutes: 30,
        );

        $this->assertSame(
            [
                ['time' => '09:00', 'available' => true],
                ['time' => '09:30', 'available' => false],
            ],
            array_map(static fn (array $option): array => [
                'time' => $option['range']->start->format('H:i'),
                'available' => $option['available'],
            ], $options),
        );
    }
}