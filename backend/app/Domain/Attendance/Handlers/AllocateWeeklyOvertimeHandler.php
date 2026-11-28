<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\AllocateWeeklyOvertime;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\Attendance\Services\WeeklyOvertimeCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceWeeklyOvertimeAllocation;
use Illuminate\Support\Carbon;

/**
 * 週40時間超の振分を週単位でまとめて記録する。日ごとの検査のあと、週の合計が週40時間超の時間を
 * 超えていないかを検査する(超えたら例外で全体を戻す)。日ごとの上限は前の振分のReadModelを読むため、
 * 日ごとに順にpersistし、週の検査はその後に行う。
 *
 * @implements CommandHandler<AllocateWeeklyOvertime>
 */
class AllocateWeeklyOvertimeHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceEditGuard $editGuard,
        private readonly WeeklyOvertimeCalculator $calculator,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof AllocateWeeklyOvertime);

        $weekStart = Carbon::parse($command->weekStartDate)->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(6);

        foreach ($command->allocations as $allocation) {
            $this->allocateDay($command, $allocation, $weekStart);
        }

        $week = $this->calculator->calculateWeek($command->allocatedByUserId, $weekStart->toDateString(), $weekEnd->toDateString());
        if ($week['allocated_weekly_statutory_excess_overtime_minutes'] > $week['weekly_statutory_excess_overtime_minutes']) {
            throw new DomainRuleException('振分時間の合計が週40時間超の時間を超えています。');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $allocation
     */
    private function allocateDay(AllocateWeeklyOvertime $command, array $allocation, Carbon $weekStart): void
    {
        $prescribedMinutes = (int) $allocation['prescribed_minutes'];
        $nonPrescribedMinutes = (int) $allocation['non_prescribed_minutes'];
        $lateNightPrescribedMinutes = (int) $allocation['late_night_prescribed_minutes'];
        $lateNightNonPrescribedMinutes = (int) $allocation['late_night_non_prescribed_minutes'];

        $day = AttendanceDay::query()->with('calculation')->findOrFail($allocation['attendance_day_id']);
        if ($day->user_id !== $command->allocatedByUserId) {
            throw new DomainRuleException('自分の勤怠にのみ週40時間超を振り分けられます。');
        }
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
            || $prescribedMinutes > $prescribedCapacity
            || $nonPrescribedMinutes > $nonPrescribedCapacity
            || $lateNightPrescribedMinutes > $prescribedMinutes
            || $lateNightNonPrescribedMinutes > $nonPrescribedMinutes
            || $lateNightPrescribedMinutes > $lateNightPrescribedCapacity
            || $lateNightNonPrescribedMinutes > $lateNightNonPrescribedCapacity
            || ($prescribedMinutes - $lateNightPrescribedMinutes) > ($prescribedCapacity - $lateNightPrescribedCapacity)
            || ($nonPrescribedMinutes - $lateNightNonPrescribedMinutes) > ($nonPrescribedCapacity - $lateNightNonPrescribedCapacity)) {
            throw new DomainRuleException('振分時間が対象日の法定内労働時間を超えています。');
        }

        AttendanceDayAggregate::retrieve($day->id)->allocateWeeklyOvertime(
            $command->weekStartDate,
            $prescribedMinutes,
            $nonPrescribedMinutes,
            $lateNightPrescribedMinutes,
            $lateNightNonPrescribedMinutes,
            $command->allocatedByUserId,
        )->persist();
    }
}
