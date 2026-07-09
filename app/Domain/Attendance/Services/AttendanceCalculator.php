<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDay;
use Illuminate\Support\Carbon;

/**
 * 日次勤怠の実働・残業・深夜・休日労働を計算する。
 *
 * 注意 (.claude/skills/attendance-calc-review 参照):
 * - 1日8時間の法定労働時間・22:00〜05:00の深夜時間は労働基準法で定められた値であり、
 *   会社設定ではないためここでは定数として扱う。
 * - 所定労働時間はwork_stylesマスタから取得し、ハードコードしない。
 * - 週40時間・変形労働時間制を含む正確な週次/月次の法定外残業判定は会社ごとに運用が
 *   異なるため、MVPでは日次単位の簡易判定のみを行う(docs/21-mvp-scope.md
 *   「複雑な残業計算は初期設計に含めるが、実装は後続フェーズでよい」)。
 *   最終的な残業計算ルールの確定は社労士確認を前提とする。
 */
class AttendanceCalculator
{
    private const LEGAL_DAILY_LIMIT_MINUTES = 480; // 労基法32条: 1日8時間

    private const LATE_NIGHT_START_HOUR = 22;

    private const LATE_NIGHT_END_HOUR = 5;

    /**
     * @return array<string, int>
     */
    public function calculate(AttendanceDay $day): array
    {
        $start = $day->actual_start_at;
        $end = $day->actual_end_at;
        $breaks = $day->breaks->filter(fn ($break) => $break->break_end_at !== null);

        $breakMinutes = $breaks->sum(fn ($break) => $break->break_start_at->diffInMinutes($break->break_end_at));

        $actualWorkMinutes = 0;
        $lateNightMinutes = 0;
        if ($start !== null && $end !== null) {
            $actualWorkMinutes = max(0, $start->diffInMinutes($end) - $breakMinutes);

            $lateNightMinutes = $this->lateNightOverlapMinutes($start, $end);
            foreach ($breaks as $break) {
                $lateNightMinutes -= $this->lateNightOverlapMinutes($break->break_start_at, $break->break_end_at);
            }
            $lateNightMinutes = max(0, $lateNightMinutes);
        }

        $shift = $day->shiftAssignment;
        $workStyle = $shift?->workStyle;
        $prescribedWorkMinutes = $workStyle?->prescribed_daily_minutes ?? 0;

        $plannedWorkMinutes = 0;
        if ($shift?->planned_start_at !== null && $shift?->planned_end_at !== null) {
            $plannedWorkMinutes = max(0, $shift->planned_start_at->diffInMinutes($shift->planned_end_at) - $shift->planned_break_minutes);
        }

        $isLegalHoliday = (bool) ($shift?->is_legal_holiday);
        $isCompanyHoliday = (bool) ($shift?->is_company_holiday) && ! $isLegalHoliday;

        $nonStatutoryOvertimeMinutes = 0;
        $statutoryOvertimeMinutes = 0;
        if (! $isLegalHoliday && ! $isCompanyHoliday) {
            $statutoryOvertimeMinutes = max(0, $actualWorkMinutes - self::LEGAL_DAILY_LIMIT_MINUTES);
            $withinLegalMinutes = min($actualWorkMinutes, self::LEGAL_DAILY_LIMIT_MINUTES);
            $nonStatutoryOvertimeMinutes = max(0, $withinLegalMinutes - $prescribedWorkMinutes);
        }

        return [
            'planned_work_minutes' => $plannedWorkMinutes,
            'actual_work_minutes' => $actualWorkMinutes,
            'prescribed_work_minutes' => $prescribedWorkMinutes,
            'non_statutory_overtime_minutes' => $nonStatutoryOvertimeMinutes,
            'statutory_overtime_minutes' => $statutoryOvertimeMinutes,
            'late_night_minutes' => $isLegalHoliday ? 0 : $lateNightMinutes,
            'legal_holiday_work_minutes' => $isLegalHoliday ? $actualWorkMinutes : 0,
            'company_holiday_work_minutes' => $isCompanyHoliday ? $actualWorkMinutes : 0,
            'legal_holiday_late_night_minutes' => $isLegalHoliday ? $lateNightMinutes : 0,
        ];
    }

    private function lateNightOverlapMinutes(Carbon $start, Carbon $end): int
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $total = 0;
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lessThan($end)) {
            $windowStart = $cursor->copy()->setTime(self::LATE_NIGHT_START_HOUR, 0);
            $windowEnd = $cursor->copy()->addDay()->setTime(self::LATE_NIGHT_END_HOUR, 0);
            $total += $this->overlapMinutes($start, $end, $windowStart, $windowEnd);
            $cursor->addDay();
        }

        return $total;
    }

    private function overlapMinutes(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): int
    {
        $overlapStart = $aStart->greaterThan($bStart) ? $aStart : $bStart;
        $overlapEnd = $aEnd->lessThan($bEnd) ? $aEnd : $bEnd;

        return $overlapEnd->greaterThan($overlapStart) ? $overlapStart->diffInMinutes($overlapEnd) : 0;
    }
}
