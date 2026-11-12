<?php

namespace Tests\Unit;

use App\Domain\Availability\Services\CapacityEvaluator;
use App\Domain\Availability\ValueObjects\TimeRange;
use DateTimeImmutable;
use Tests\TestCase;

class CapacityEvaluatorTest extends TestCase
{
    public function test_peak_occupancy_accepts_non_overlapping_bookings_touching_at_a_boundary(): void
    {
        $evaluator = new CapacityEvaluator;
        $candidate = $this->range('09:30', '10:30');

        $this->assertTrue($evaluator->allows(2, $candidate, [
            $this->range('09:00', '10:00'),
            $this->range('10:00', '11:00'),
        ]));
    }

    public function test_peak_occupancy_rejects_a_third_simultaneous_booking(): void
    {
        $evaluator = new CapacityEvaluator;

        $this->assertFalse($evaluator->allows(2, $this->range('09:30', '10:30'), [
            $this->range('09:00', '10:15'),
            $this->range('09:45', '10:45'),
        ]));
    }

    public function test_capacity_is_calculated_from_partial_overlaps_and_buffers(): void
    {
        $evaluator = new CapacityEvaluator;
        $candidateWithBuffers = $this->range('09:45', '10:30');

        $this->assertTrue($evaluator->allows(2, $candidateWithBuffers, [
            $this->range('09:00', '10:00'),
            $this->range('10:00', '10:45'),
        ]));
        $this->assertSame(1, $evaluator->peakOccupancy([
            $this->range('09:00', '10:00'),
            $this->range('10:00', '11:00'),
        ]));
    }

    private function range(string $start, string $end): TimeRange
    {
        return new TimeRange(
            new DateTimeImmutable('2026-11-20 '.$start.':00 UTC'),
            new DateTimeImmutable('2026-11-20 '.$end.':00 UTC'),
        );
    }
}
