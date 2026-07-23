<?php

namespace App\Domain\Attendance\Projectors;

use App\Domain\Attendance\Events\AttendanceBreakAutoInserted;
use App\Domain\Attendance\Events\AttendanceDayCreated;
use App\Domain\Attendance\Events\AttendanceDayDeleted;
use App\Domain\Attendance\Events\AttendanceDayEdited;
use App\Domain\Attendance\Events\AttendanceDayLiveStatusSynced;
use App\Domain\Attendance\Events\AttendanceDaySyncedFromPunches;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * attendance_day.*イベントからattendance_days / attendance_breaks / attendance_leave_segments
 * を作成・更新する(.claude/skills/add-projection参照)。attendance_day.calculated /
 * attendance_day.daily_calculation_adjustedはAttendanceDailyCalculationProjectorが担当する。
 */
class AttendanceDayProjector extends Projector
{
    public function onAttendanceDayCreated(AttendanceDayCreated $event): void
    {
        $day = AttendanceDay::query()->updateOrCreate(
            ['id' => $event->aggregateRootUuid()],
            [
                'user_id' => $event->userId,
                'work_date' => $event->workDate,
                'shift_assignment_id' => $event->shiftAssignmentId,
                'status' => $event->status,
                'source' => $event->source,
                'utc_offset_minutes' => $event->utcOffsetMinutes,
                'actual_start_at' => $this->parse($event->actualStartAt),
                'actual_end_at' => $this->parse($event->actualEndAt),
                'work_type' => $event->workType,
                'work_location_type' => $event->workLocationType,
                'note' => $event->note,
            ],
        );

        $this->replaceBreaks($day, $event->breaks);
        $this->replaceLeaveSegments($day, $event->leaveSegments);
    }

    public function onAttendanceDayEdited(AttendanceDayEdited $event): void
    {
        $day = AttendanceDay::query()->findOrFail($event->aggregateRootUuid());

        $day->utc_offset_minutes = $event->utcOffsetMinutes;
        $day->actual_start_at = $this->parse($event->actualStartAt);
        $day->actual_end_at = $this->parse($event->actualEndAt);
        $day->status = $event->status;
        $day->work_type = $event->workType;
        if ($event->workLocationTypeProvided) {
            $day->work_location_type = $event->workLocationType;
        }
        $day->note = $event->note;
        $day->save();

        $this->replaceBreaks($day, $event->breaks);
        $this->replaceLeaveSegments($day, $event->leaveSegments);
    }

    public function onAttendanceDayDeleted(AttendanceDayDeleted $event): void
    {
        // attendance_breaks / attendance_leave_segments / attendance_daily_calculations は
        // 外部キーのcascadeOnDeleteで併せて削除される。
        AttendanceDay::query()->whereKey($event->aggregateRootUuid())->delete();
    }

    public function onAttendanceDayLiveStatusSynced(AttendanceDayLiveStatusSynced $event): void
    {
        $day = AttendanceDay::query()->find($event->aggregateRootUuid());

        if ($day === null) {
            $attributes = [
                'user_id' => $event->userId,
                'work_date' => $event->workDate,
                'shift_assignment_id' => $event->shiftAssignmentId,
                'status' => $event->status,
                'source' => $event->source,
            ];
            if ($event->actualStartAt !== null) {
                $attributes['actual_start_at'] = $this->parse($event->actualStartAt);
                $attributes['utc_offset_minutes'] = $event->utcOffsetMinutes;
            }

            AttendanceDay::query()->create(array_merge(['id' => $event->aggregateRootUuid()], $attributes));

            return;
        }

        $day->status = $event->status;
        $day->source = $event->source;
        if ($event->actualStartAt !== null && $day->actual_start_at === null) {
            $day->actual_start_at = $this->parse($event->actualStartAt);
            $day->utc_offset_minutes = $event->utcOffsetMinutes;
        }
        $day->save();
    }

    public function onAttendanceDaySyncedFromPunches(AttendanceDaySyncedFromPunches $event): void
    {
        $attributes = [
            'user_id' => $event->userId,
            'work_date' => $event->workDate,
            'shift_assignment_id' => $event->shiftAssignmentId,
            'actual_start_at' => $this->parse($event->actualStartAt),
            'actual_end_at' => $this->parse($event->actualEndAt),
            'utc_offset_minutes' => $event->utcOffsetMinutes,
            'status' => AttendanceDayStatus::CLOCKED_OUT,
            'source' => AttendanceDaySource::PUNCH,
        ];

        // どの端末で打刻したか分からない場合は既存の値を保持する(勝手にクリアしない)。
        if ($event->workLocationType !== null) {
            $attributes['work_location_type'] = $event->workLocationType;
        }

        $day = AttendanceDay::query()->updateOrCreate(['id' => $event->aggregateRootUuid()], $attributes);

        $this->replaceBreaks($day, $event->breaks);
    }

    public function onAttendanceBreakAutoInserted(AttendanceBreakAutoInserted $event): void
    {
        $day = AttendanceDay::query()->find($event->aggregateRootUuid());
        if ($day === null) {
            return;
        }

        if ($day->breaks()->count() > 0) {
            // 既にこのイベント適用後の再生などで休憩が存在する場合は重複作成しない。
            return;
        }

        $day->breaks()->create([
            'break_start_at' => LocalDateTime::splitOffset($event->breakStartAt)[0],
            'break_end_at' => LocalDateTime::splitOffset($event->breakEndAt)[0],
        ]);
    }

    private function parse(?string $value): ?Carbon
    {
        return $value !== null ? LocalDateTime::splitOffset($value)[0] : null;
    }

    /**
     * @param  array<int, array{start: string, end: string|null}>  $breaks
     */
    private function replaceBreaks(AttendanceDay $day, array $breaks): void
    {
        $day->breaks()->delete();
        foreach ($breaks as $break) {
            $day->breaks()->create([
                'break_start_at' => LocalDateTime::splitOffset($break['start'])[0],
                'break_end_at' => $break['end'] !== null ? LocalDateTime::splitOffset($break['end'])[0] : null,
            ]);
        }
    }

    /**
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     */
    private function replaceLeaveSegments(AttendanceDay $day, array $leaveSegments): void
    {
        $day->leaveSegments()->delete();
        foreach ($leaveSegments as $segment) {
            $day->leaveSegments()->create([
                'start_at' => LocalDateTime::splitOffset($segment['start'])[0],
                'end_at' => LocalDateTime::splitOffset($segment['end'])[0],
                'note' => $segment['note'] ?? null,
            ]);
        }
    }
}
