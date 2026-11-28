<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 週40時間超の時間を、週の各日へまとめて振り分ける(週1つ。全部か無しか)。
 *
 * `allocations`の各要素: attendance_day_id, prescribed_minutes, non_prescribed_minutes,
 * late_night_prescribed_minutes, late_night_non_prescribed_minutes。
 */
class AllocateWeeklyOvertime implements Command
{
    /**
     * @param  list<array{attendance_day_id: string, prescribed_minutes: int, non_prescribed_minutes: int, late_night_prescribed_minutes: int, late_night_non_prescribed_minutes: int}>  $allocations
     */
    public function __construct(
        public readonly string $weekStartDate,
        public readonly array $allocations,
        public readonly string $allocatedByUserId,
    ) {}
}
