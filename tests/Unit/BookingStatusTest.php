<?php

namespace Tests\Unit;

use App\Enums\BookingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookingStatusTest extends TestCase
{
    #[DataProvider('validTransitions')]
    public function test_allowed_transitions_are_recognized(BookingStatus $from, BookingStatus $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    public function test_invalid_and_terminal_transitions_are_rejected(): void
    {
        $this->assertFalse(BookingStatus::Held->canTransitionTo(BookingStatus::Completed));
        $this->assertFalse(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Expired));
        $this->assertFalse(BookingStatus::Cancelled->canTransitionTo(BookingStatus::Confirmed));
        $this->assertSame([], BookingStatus::Expired->allowedTransitions());
    }

    public static function validTransitions(): array
    {
        return [
            'held to confirmed' => [BookingStatus::Held, BookingStatus::Confirmed],
            'held to cancelled' => [BookingStatus::Held, BookingStatus::Cancelled],
            'held to expired' => [BookingStatus::Held, BookingStatus::Expired],
            'confirmed to checked in' => [BookingStatus::Confirmed, BookingStatus::CheckedIn],
            'confirmed to cancelled' => [BookingStatus::Confirmed, BookingStatus::Cancelled],
            'confirmed to no show' => [BookingStatus::Confirmed, BookingStatus::NoShow],
            'checked in to completed' => [BookingStatus::CheckedIn, BookingStatus::Completed],
            'checked in to no show' => [BookingStatus::CheckedIn, BookingStatus::NoShow],
        ];
    }
}