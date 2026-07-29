<?php

namespace App\Domain\Attendance\Services;

use App\Models\WorkCalendarDay;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * 勤務予定(employee_shift_assignments)が未展開の日について、対象日が所定労働日かどうかを
 * 判定する。優先順位はWorkStyleFallbackResolverと同じ(その月に割り当てられた働き方 →
 * システム全体設定のデフォルト働き方)。有給・特別休暇の申請は`employee_shift_assignments`が
 * 事前展開されていることを前提にできない(通常勤務は運用上シフト生成が行われないことが多い)ため、
 * PaidLeave/SpecialLeaveのRequestハンドラから利用する(docs/08-usecases-calendar-shift.md参照)。
 */
class ScheduledWorkingDayResolver
{
    public function __construct(private readonly WorkStyleFallbackResolver $workStyleFallbackResolver) {}

    public function isWorkingDay(string $userId, Carbon $date): bool
    {
        $workStyle = $this->workStyleFallbackResolver->resolveForUser($userId, $date);

        return $workStyle !== null && $this->isWorkingDayForWorkStyle($workStyle, $date);
    }

    public function resolveWorkStyle(string $userId, Carbon $date): ?WorkStyle
    {
        return $this->workStyleFallbackResolver->resolveForUser($userId, $date);
    }

    /**
     * 働き方に会社カレンダーが設定されていれば、その日区分に従う。カレンダー未設定
     * (calendar_id === null。「通常勤務」のデフォルト働き方はこれに該当する)の場合は、
     * 土日を除く平日を所定労働日とみなす(FlexSettlementSummaryCalculator::workingDatesWithinPeriod
     * と同じ考え方)。
     */
    private function isWorkingDayForWorkStyle(WorkStyle $workStyle, Carbon $date): bool
    {
        if ($workStyle->calendar_id !== null) {
            return (bool) WorkCalendarDay::query()
                ->where('calendar_id', $workStyle->calendar_id)
                ->whereDate('date', $date->toDateString())
                ->value('is_working_day');
        }

        return ! $date->isWeekend();
    }
}
