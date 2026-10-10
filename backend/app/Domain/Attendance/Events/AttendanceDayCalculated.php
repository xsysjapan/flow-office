<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.calculated
 *
 * AttendanceDailyCalculationProjector(spatie Projector)がこのイベントのcalculationを
 * そのままattendance_daily_calculationsへ反映する(再計算ロジックはHandler側のみに置く)。
 *
 * 末尾の利用者ID・勤務日(Y-m-d)は、代休口座などの他の文脈が勤怠日テーブルを読まずに判定できるよう
 * 記録する(仕様確定事項I。既定値nullは本変更前の保存イベント用)。
 */
class AttendanceDayCalculated extends ShouldBeStored
{
    /**
     * @param  array<string, int|bool|float|null>  $calculation
     */
    public function __construct(
        public readonly array $calculation,
        public readonly ?string $userId = null,
        public readonly ?string $workDate = null,
    ) {}
}
