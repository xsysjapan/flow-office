<?php

namespace App\Domain\Attendance\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

/**
 * attendance.day_calculated
 *
 * AttendanceDailyCalculationProjectorはこのイベントのpayloadをそのまま
 * attendance_daily_calculationsへ反映する(再計算ロジックはHandler側のみに置く)。
 */
class AttendanceDayCalculated implements DomainEvent
{
    /**
     * @param  array<string, int|bool|null>  $calculation
     */
    public function __construct(
        public readonly int $attendanceDayId,
        public readonly array $calculation,
    ) {}

    public function eventType(): string
    {
        return 'attendance.day_calculated';
    }

    public function payload(): array
    {
        return array_merge(['attendance_day_id' => $this->attendanceDayId], $this->calculation);
    }
}
