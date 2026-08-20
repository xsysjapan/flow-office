<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\AllocateAttendanceWeeklyOvertime;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceWeeklyOvertimeAllocation;
use Illuminate\Support\Carbon;

class AllocateAttendanceWeeklyOvertimeHandler implements CommandHandler
{
    public function __construct(private readonly AttendanceEditGuard $editGuard) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof AllocateAttendanceWeeklyOvertime);
        $day = AttendanceDay::query()->with('calculation')->findOrFail($command->attendanceDayId);
        if ($day->user_id !== $command->allocatedByUserId) {
            throw new DomainRuleException('自分の勤怠にのみ週40時間超を振り分けられます。');
        }
        $weekStart = Carbon::parse($command->weekStartDate)->startOfDay();
        if ($day->work_date->lt($weekStart) || $day->work_date->gt($weekStart->copy()->addDays(6))) {
            throw new DomainRuleException('選択した勤務日は対象週に含まれていません。');
        }
        $this->editGuard->assertMutable($day, $day->user_id, $day->work_date->toDateString());
        $calculation = $day->calculation;
        $previous = AttendanceWeeklyOvertimeAllocation::query()
            ->where('attendance_day_id', $day->id)
            ->first();
        $prescribedCapacity = ($calculation?->prescribed_statutory_within_work_minutes ?? 0)
            + ($previous?->prescribed_minutes ?? 0);
        $nonPrescribedCapacity = ($calculation?->non_prescribed_statutory_within_work_minutes ?? 0)
            + ($previous?->non_prescribed_minutes ?? 0);
        $lateNightPrescribedCapacity = ($calculation?->late_night_prescribed_statutory_within_work_minutes ?? 0)
            + ($previous?->late_night_prescribed_minutes ?? 0);
        $lateNightNonPrescribedCapacity = ($calculation?->late_night_non_prescribed_statutory_within_work_minutes ?? 0)
            + ($previous?->late_night_non_prescribed_minutes ?? 0);
        if ($calculation === null
            || $command->prescribedMinutes > $prescribedCapacity
            || $command->nonPrescribedMinutes > $nonPrescribedCapacity
            || $command->lateNightPrescribedMinutes > $command->prescribedMinutes
            || $command->lateNightNonPrescribedMinutes > $command->nonPrescribedMinutes
            || $command->lateNightPrescribedMinutes > $lateNightPrescribedCapacity
            || $command->lateNightNonPrescribedMinutes > $lateNightNonPrescribedCapacity
            || ($command->prescribedMinutes - $command->lateNightPrescribedMinutes) > ($prescribedCapacity - $lateNightPrescribedCapacity)
            || ($command->nonPrescribedMinutes - $command->lateNightNonPrescribedMinutes) > ($nonPrescribedCapacity - $lateNightNonPrescribedCapacity)) {
            throw new DomainRuleException('振分時間が対象日の法定内労働時間を超えています。');
        }

        AttendanceDayAggregate::retrieve($day->id)->allocateWeeklyOvertime(
            $command->weekStartDate,
            $command->prescribedMinutes,
            $command->nonPrescribedMinutes,
            $command->lateNightPrescribedMinutes,
            $command->lateNightNonPrescribedMinutes,
            $command->allocatedByUserId,
        )->persist();

        return $day->fresh();
    }
}
