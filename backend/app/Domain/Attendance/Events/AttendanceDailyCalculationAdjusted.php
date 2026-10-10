<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.daily_calculation_adjusted
 *
 * AttendanceDailyCalculationProjectorはこのイベントのpayloadを、直前のattendance_day.calculated
 * が作った行に上書きで反映する(is_manually_adjusted=trueにする)。その後日次実績が再編集され
 * attendance_day.calculatedが再発生すると、この補正は解除される。
 *
 * 末尾の利用者ID・勤務日・日区分・実労働分は、代休口座などの他の文脈が勤怠日テーブルを読まずに判定できるよう
 * 記録する(既定値nullは本変更前の保存イベント用。仕様確定事項I)。
 *
 * * $payrollWorkMinutes・$lateNightPrescribedHolidayWorkMinutesはnullable(かつデフォルトnull)に
 * してある。このイベントのspatie上のデシリアライズは名前付きコンストラクタ引数への復元であり、
 * これらの項目が追加される前に記録された行をnullable型なしで再生するとMissingConstructorArgumentsException
 * になるため(`.claude/skills/attendance-calc-review`参照)。Projector側はnullの場合、直前の
 * attendance_daily_calculations行の値を保持する。
 */
class AttendanceDailyCalculationAdjusted extends ShouldBeStored
{
    public function __construct(
        public readonly int $prescribedWorkMinutes,
        public readonly int $statutoryWithinOvertimeMinutes,
        public readonly int $statutoryExcessOvertimeMinutes,
        public readonly int $legalHolidayWorkMinutes,
        public readonly int $prescribedHolidayWorkMinutes,
        public readonly ?int $payrollWorkMinutes,
        public readonly int $lateNightPrescribedWorkMinutes,
        public readonly int $lateNightStatutoryWithinOvertimeMinutes,
        public readonly int $lateNightStatutoryExcessOvertimeMinutes,
        public readonly int $lateNightLegalHolidayWorkMinutes,
        public readonly ?int $lateNightPrescribedHolidayWorkMinutes,
        public readonly string $reason,
        public readonly string $adjustedByUserId,
        public readonly ?string $userId = null,
        public readonly ?string $workDate = null,
        public readonly ?string $dayClassification = null,
        public readonly ?int $workMinutes = null,
    ) {}
}
