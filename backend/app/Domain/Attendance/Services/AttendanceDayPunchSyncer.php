<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDaySyncedFromPunches;
use App\Domain\EventSourcing\EventStore;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\AttendancePunch;
use App\Models\EmployeeShiftAssignment;
use App\Models\PunchStatus;
use App\Support\LocalDateTime;

/**
 * UC-A012〜UC-A014: 有効な打刻ログ(`status=active`)を集めて、矛盾なく1日分の勤務として
 * 組み立てられる場合のみ attendance_days / attendance_breaks に反映する。打刻の記録・訂正・
 * 削除のいずれの後にも同じ規則で呼び出される。
 */
class AttendanceDayPunchSyncer
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendancePunchReconciler $reconciler,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function sync(int $userId, string $workDate): void
    {
        $punches = AttendancePunch::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->where('status', PunchStatus::ACTIVE)
            ->orderBy('punched_at')
            ->get();

        $reconciled = $this->reconciler->reconcile($punches);
        if ($reconciled === null) {
            return;
        }

        $day = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($day !== null && $day->source !== AttendanceDaySource::PUNCH) {
            // 画面からの操作・日次編集で既に確定した日は、打刻ログで上書きしない。
            return;
        }

        if (! $this->guard->isMutable($day, $userId, $workDate)) {
            // 締め後にロック済み、または承認済み・締め済みの月に属する日は、
            // 打刻の記録・訂正・削除では上書きしない(修正申請ワークフローを使う)。
            return;
        }

        if ($day === null) {
            $shiftAssignment = EmployeeShiftAssignment::query()
                ->where('user_id', $userId)
                ->whereDate('work_date', $workDate)
                ->first();

            $day = AttendanceDay::query()->create([
                'user_id' => $userId,
                'work_date' => $workDate,
                'shift_assignment_id' => $shiftAssignment?->id,
                'status' => AttendanceDayStatus::NOT_STARTED,
                'source' => AttendanceDaySource::PUNCH,
            ]);
        }

        $day->actual_start_at = $reconciled['clock_in'];
        $day->actual_end_at = $reconciled['clock_out'];
        $day->utc_offset_minutes = $reconciled['utc_offset_minutes'];
        $day->status = AttendanceDayStatus::CLOCKED_OUT;
        $day->source = AttendanceDaySource::PUNCH;
        $day->save();

        $day->breaks()->delete();
        foreach ($reconciled['breaks'] as $break) {
            $day->breaks()->create([
                'break_start_at' => $break['start'],
                'break_end_at' => $break['end'],
            ]);
        }

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDaySyncedFromPunches(
                attendanceDayId: $day->id,
                actualStartAt: LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes),
                actualEndAt: LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes),
            ),
        );

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'));

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayCalculated(
                attendanceDayId: $day->id,
                calculation: $calculation,
            ),
        );
    }
}
