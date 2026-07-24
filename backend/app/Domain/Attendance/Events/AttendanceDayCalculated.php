<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.calculated
 *
 * AttendanceDailyCalculationProjector(spatie Projector)がこのイベントのcalculationを
 * そのままattendance_daily_calculationsへ反映する(再計算ロジックはHandler側のみに置く)。
 */
class AttendanceDayCalculated extends ShouldBeStored
{
    /**
     * @param  array<string, int|bool|float|null>  $calculation
     */
    public function __construct(
        public readonly array $calculation,
    ) {}
}
