<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Models\AttendanceBreak;
use App\Models\AttendanceDay;
use App\Models\WorkStyle;
use App\Support\LocalDateTime;

/**
 * 指示書: 1日分の勤務が確定した際、働き方(work_styles.auto_break_enabled)が有効で、
 * その日にまだ休憩が1件も記録されていない場合に限り、標準休憩(default_break_start_time〜
 * default_break_end_time)を自動でattendance_breaksへ補完する。実際に打刻・編集された
 * 休憩が1件でもあれば何もしない(上書き・重複させない)。
 *
 * 打刻経路(`AttendanceDayPunchSyncer`)・日次編集経路(`EditAttendanceDayHandler`)の
 * どちらから確定した実績にも同じ規則を適用する(操作経路ごとに計算ロジックを複製しない。
 * docs/03-architecture.md 3.5)。
 *
 * 適用条件(.claude/skills/attendance-calc-review参照。いずれも所定労働時間・休憩時刻の
 * マスタ設定のみを根拠にし、8時間等の法定値をここでハードコードしない):
 * - 対象日の働き方でauto_break_enabledが有効
 * - 働き方にdefault_break_start_time・default_break_end_timeが両方設定されている
 * - 実働時間(出勤〜退勤)が6時間以上
 * - 標準休憩の時間帯が実働時間内に完全に収まる
 * - その日に休憩が1件も記録されていない
 */
class AttendanceStandardBreakInserter
{
    /** 標準休憩の自動補完を検討する最短実働時間(6時間)。 */
    private const AUTO_BREAK_MINIMUM_WORK_MINUTES = 360;

    public function __construct(private readonly WorkStyleFallbackResolver $workStyleFallbackResolver) {}

    /**
     * 補完した場合はtrueを返す(呼び出し元は$aggregateを永続化する必要がある)。$dayの
     * `breaks`リレーションには、補完した休憩をその場で反映する(まだDBに保存されていない
     * 実績からの呼び出しでも、直後の集計処理がこの休憩を認識できるようにするため)。
     */
    public function insertIfApplicable(AttendanceDayAggregate $aggregate, AttendanceDay $day): bool
    {
        if ($day->breaks->isNotEmpty()) {
            return false;
        }

        $start = $day->actual_start_at;
        $end = $day->actual_end_at;
        if ($start === null || $end === null) {
            return false;
        }

        if ($start->diffInMinutes($end) < self::AUTO_BREAK_MINIMUM_WORK_MINUTES) {
            return false;
        }

        $workStyle = $day->calendarEntry?->workStyle
            ?? $this->workStyleFallbackResolver->resolveForUser($day->user_id, $day->work_date->copy());

        if (! $this->supportsAutoBreak($workStyle)) {
            return false;
        }

        $breakStart = $day->work_date->copy()->setTimeFromTimeString($workStyle->default_break_start_time);
        $breakEnd = $day->work_date->copy()->setTimeFromTimeString($workStyle->default_break_end_time);

        if ($breakEnd->lessThanOrEqualTo($breakStart)) {
            return false;
        }

        if (! ($start->lessThanOrEqualTo($breakStart) && $breakEnd->lessThanOrEqualTo($end))) {
            return false;
        }

        $aggregate->autoInsertBreak(
            workStyleId: $workStyle->id,
            breakStartAt: LocalDateTime::formatWithOffsetMinutes($breakStart, $day->utc_offset_minutes),
            breakEndAt: LocalDateTime::formatWithOffsetMinutes($breakEnd, $day->utc_offset_minutes),
        );

        // 呼び出し元がこの直後に集計する場合に備え、補完した休憩をその場で反映する。
        $day->setRelation(
            'breaks',
            $day->breaks->push(new AttendanceBreak(['break_start_at' => $breakStart, 'break_end_at' => $breakEnd])),
        );

        return true;
    }

    private function supportsAutoBreak(?WorkStyle $workStyle): bool
    {
        return $workStyle !== null
            && $workStyle->auto_break_enabled
            && $workStyle->default_break_start_time !== null
            && $workStyle->default_break_end_time !== null;
    }
}
