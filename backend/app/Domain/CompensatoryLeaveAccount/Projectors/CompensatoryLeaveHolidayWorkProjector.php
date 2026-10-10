<?php

namespace App\Domain\CompensatoryLeaveAccount\Projectors;

use App\Domain\Attendance\Events\AttendanceDailyCalculationAdjusted;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Models\CompensatoryHolidayWorkDay;
use Illuminate\Support\Carbon;
use App\Models\DayClassification;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * 勤怠日の計算イベントから、代休口座が見る休日出勤のビュー(compensatory_holiday_work_days)を作る。
 * 利用者×勤務日の日区分と実労働分だけを持つ(手動付与の休日出勤の判定に使う。勤怠日テーブルは読まない)。
 *
 * 行は計算イベントごとに上書きし、勤怠日の削除で外す。利用者・勤務日が無いイベント(本変更前の保存イベント)は無視する。
 */
class CompensatoryLeaveHolidayWorkProjector extends Projector
{
    public function onAttendanceDayCalculated(AttendanceDayCalculated $event): void
    {
        if ($event->userId === null || $event->workDate === null) {
            return;
        }

        $dayClassification = $event->calculation['day_classification'] ?? null;

        $this->upsert(
            userId: $event->userId,
            workDate: Carbon::parse($event->workDate)->toDateString(),
            isHolidayDay: $this->isHolidayClassification(is_string($dayClassification) ? $dayClassification : null),
            workMinutes: (int) ($event->calculation['work_minutes'] ?? 0),
        );
    }

    public function onAttendanceDailyCalculationAdjusted(AttendanceDailyCalculationAdjusted $event): void
    {
        if ($event->userId === null || $event->workDate === null || $event->workMinutes === null) {
            return;
        }

        $this->upsert(
            userId: $event->userId,
            workDate: Carbon::parse($event->workDate)->toDateString(),
            isHolidayDay: $this->isHolidayClassification($event->dayClassification),
            workMinutes: $event->workMinutes,
        );
    }

    public function onAttendanceDayDeleted(AttendanceDayDeleted $event): void
    {
        CompensatoryHolidayWorkDay::query()
            ->where('user_id', $event->userId)
            ->whereDate('work_date', $event->workDate)
            ->delete();
    }

    private function upsert(string $userId, string $workDate, bool $isHolidayDay, int $workMinutes): void
    {
        CompensatoryHolidayWorkDay::query()->updateOrCreate(
            ['user_id' => $userId, 'work_date' => $workDate],
            ['is_holiday_day' => $isHolidayDay, 'work_minutes' => $workMinutes],
        );
    }

    private function isHolidayClassification(?string $dayClassification): bool
    {
        return in_array($dayClassification, [DayClassification::PRESCRIBED_HOLIDAY, DayClassification::LEGAL_HOLIDAY], true);
    }
}
