<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_day.corrected
 *
 * 補正専用イベント(.claude/skills/data-correction ステップ3、変更セット論点12・仕様確定事項H)。
 * 過去のイベントを書き換えずに、勤怠日の「現在の正しい状態一式」を今の時点の事実として追記する。
 *
 * AttendanceDayProjectorが次を全て置き換える(行が無ければ作る=欠落した attendance_day.created の補完):
 * - 勤怠日の列(利用者・勤務日・カレンダー・status・source・UTCオフセット・実績・work_type・
 *   勤務場所・備考・日区分)、休憩、不就労区間
 * - 日次計算(attendance_daily_calculations): dailyCalculationがnullなら行を消す
 * - 週40時間の配賦(attendance_weekly_overtime_allocations): weeklyOvertimeAllocationがnullなら行を消す
 * 日次計算の値・手動調整の値は記録済みの最終値をそのまま置く(差分の再適用はしない)。
 *
 * 出勤率ビュー(休暇文脈)は利用者・勤務日・退勤済みの状態を、代休の休日出勤ビューは日区分と実労働分を
 * このイベントから作る。locked_at(月次ロック)は月次の状態のため含めない。
 *
 * - dailyCalculation: attendance_daily_calculationsの列(attendance_day_id・id・timestamps を除く)。
 * - weeklyOvertimeAllocation: attendance_weekly_overtime_allocationsの列(attendance_day_id を除く)。
 * - reason・correctedByUserId: 補正の理由と操作者。correctionId: 補正ID(同じIDの再実行は記録しない)。
 */
class AttendanceDayCorrected extends ShouldBeStored
{
    /**
     * @param  array<int, array{start: string, end: string|null}>  $breaks
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     * @param  array<string, mixed>|null  $dailyCalculation
     * @param  array<string, mixed>|null  $weeklyOvertimeAllocation
     */
    public function __construct(
        public readonly string $correctionId,
        public readonly string $userId,
        public readonly string $workDate,
        public readonly ?string $calendarEntryId,
        public readonly string $status,
        public readonly string $source,
        public readonly int $utcOffsetMinutes,
        public readonly ?string $actualStartAt,
        public readonly ?string $actualEndAt,
        public readonly ?string $workType,
        public readonly ?string $workLocationType,
        public readonly ?string $note,
        public readonly ?string $dayClassification,
        public readonly array $breaks,
        public readonly array $leaveSegments,
        public readonly ?array $dailyCalculation,
        public readonly ?array $weeklyOvertimeAllocation,
        public readonly string $reason,
        public readonly string $correctedByUserId,
    ) {}
}
