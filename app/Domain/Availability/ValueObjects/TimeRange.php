<?php

namespace App\Domain\Availability\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TimeRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($end <= $start) {
            throw new InvalidArgumentException('A time range must end after it starts.');
        }
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }
}