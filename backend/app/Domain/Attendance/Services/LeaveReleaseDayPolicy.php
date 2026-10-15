<?php

namespace App\Domain\Attendance\Services;

use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceWeeklyOvertimeAllocation;

/**
 * 休暇の差戻し・取消で休暇が外れたとき、その勤怠日を削除してよいかの判定(論点15・仕様確定事項I)。
 *
 * 削除してよいのは次の全てを満たす日だけ:
 * - source=leave(休暇のために勤怠が作った日)
 * - 他の有効な休暇が無い(呼び出し側が判定して渡す)
 * - 実績が無い: 出退勤時刻・休憩・不就労区間が無い
 * - 入力が無い: 作業内容(work_type)・勤務形態区分(work_location_type)・備考(note)が空
 * - 手動調整(attendance_daily_calculations.is_manually_adjusted)が無い
 * - 週40時間の配賦(attendance_weekly_overtime_allocations)が無い
 *
 * いずれかに当てはまれば削除せず、日次計算を記録し直す(呼び出し側の責務)。
 * DB・Eloquentに依存するが、Handlerには判定を埋め込まずこのクラスで判定する。
 */
class LeaveReleaseDayPolicy
{
    public function isRemovableAfterLeaveRelease(AttendanceDay $day, bool $hasOtherActiveLeave): bool
    {
        if ($day->source !== AttendanceDaySource::LEAVE) {
            return false;
        }

        if ($hasOtherActiveLeave) {
            return false;
        }

        if ($day->actual_start_at !== null || $day->actual_end_at !== null) {
            return false;
        }

        if ($day->breaks()->exists() || $day->leaveSegments()->exists()) {
            return false;
        }

        if ($this->filled($day->work_type) || $this->filled($day->work_location_type) || $this->filled($day->note)) {
            return false;
        }

        if (AttendanceDailyCalculation::query()
            ->where('attendance_day_id', $day->id)
            ->where('is_manually_adjusted', true)
            ->exists()) {
            return false;
        }

        if (AttendanceWeeklyOvertimeAllocation::query()->where('attendance_day_id', $day->id)->exists()) {
            return false;
        }

        return true;
    }

    private function filled(?string $value): bool
    {
        return $value !== null && $value !== '';
    }
}
