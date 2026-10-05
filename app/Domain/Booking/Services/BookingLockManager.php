<?php

namespace App\Domain\Booking\Services;

use App\Models\BookingDayLock;
use App\Models\Business;
use App\Models\Staff;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class BookingLockManager
{
    public function lockForStaffRange(
        Business $business,
        Staff $staff,
        CarbonInterface $occupiedStart,
        CarbonInterface $occupiedEnd,
        string $timezone,
    ): void
    {
        $this->lockStaffRanges($business, [[
            'staff' => $staff,
            'start' => $occupiedStart,
            'end' => $occupiedEnd,
            'timezone' => $timezone,
        ]]);
    }

    /**
     * @param list<array{staff:Staff,start:CarbonInterface,end:CarbonInterface,timezone:string}> $ranges
     */
    public function lockStaffRanges(Business $business, array $ranges): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Booking day locks must be acquired inside a database transaction.');
        }

        $lockRows = [];

        foreach ($ranges as $range) {
            $firstDate = $range['start']->copy()->setTimezone($range['timezone'])->startOfDay();
            $lastDate = $range['end']->copy()->setTimezone($range['timezone'])->subMicrosecond()->startOfDay();

            for ($date = $firstDate; $date <= $lastDate; $date = $date->addDay()) {
                $staffId = (string) $range['staff']->getKey();
                $bookingDate = $date->toDateString();
                $lockRows[$staffId.'|'.$bookingDate] = ['staff_id' => $staffId, 'booking_date' => $bookingDate];
            }
        }

        ksort($lockRows, SORT_STRING);

        foreach ($lockRows as $lockRow) {
            DB::table('booking_day_locks')->insertOrIgnore([
                'business_id' => $business->getKey(),
                'staff_id' => $lockRow['staff_id'],
                'booking_date' => $lockRow['booking_date'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        BookingDayLock::query()
            ->where('business_id', $business->getKey())
            ->where(function ($query) use ($lockRows): void {
                foreach ($lockRows as $lockRow) {
                    $query->orWhere(function ($query) use ($lockRow): void {
                        $query->where('staff_id', $lockRow['staff_id'])
                            ->where('booking_date', $lockRow['booking_date']);
                    });
                }
            })
            ->orderBy('staff_id')
            ->orderBy('booking_date')
            ->lockForUpdate()
            ->get();
    }
}