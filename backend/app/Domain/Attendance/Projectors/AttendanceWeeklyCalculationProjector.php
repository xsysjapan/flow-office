<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Services\WeeklyOvertimeAggregator;
use App\Domain\EventSourcing\Contracts\Projector;
use App\Models\AttendanceDay;
use App\Models\StoredEvent;
use Illuminate\Support\Facades\DB;

/**
 * attendance.day_calculated イベントから attendance_weekly_calculations を再生成する。
 * (.claude/skills/add-projection 参照)
 *
 * 対象の日が属する週全体を都度再集計する(週内の他の日の値は変わらないため、
 * 同じ週のイベントが複数回流れても最終結果は同じになる=冪等)。
 */
class AttendanceWeeklyCalculationProjector implements Projector
{
    public function __construct(private readonly WeeklyOvertimeAggregator $aggregator) {}

    public function eventTypes(): array
    {
        return ['attendance.day_calculated'];
    }

    public function project(StoredEvent $event): void
    {
        $attendanceDayId = $event->payload['attendance_day_id'];

        $day = AttendanceDay::query()->find($attendanceDayId);

        // UC-A015で日次勤怠自体が削除されている場合、再生成時にスキップする
        // (AttendanceDailyCalculationProjectorと同じ理由)。
        if ($day === null) {
            return;
        }

        $this->aggregator->recalculate($day->user_id, $day->work_date->toDateString());
    }

    public function reset(): void
    {
        DB::table('attendance_weekly_calculations')->truncate();
    }
}
