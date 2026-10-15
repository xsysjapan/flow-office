<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\CloseAttendanceMonth;
use App\Domain\Attendance\Services\WeeklyOvertimeAllocationGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;

/**
 * UC-A011: 管理部が月次勤怠を締める。締め後の日次勤怠の編集可否は、月次の状態
 * (attendance_months.status)とロック表(attendance_locks)でAttendanceEditGuardが判定する
 * (attendance_days.locked_atは廃止)。
 *
 * @implements CommandHandler<CloseAttendanceMonth>
 */
class CloseAttendanceMonthHandler implements CommandHandler
{
    public function __construct(private readonly WeeklyOvertimeAllocationGuard $weeklyOvertimeAllocationGuard) {}

    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof CloseAttendanceMonth);

        $month = AttendanceMonth::query()->findOrFail($command->attendanceMonthId);

        if ($month->status !== AttendanceMonthStatus::APPROVED) {
            throw new DomainRuleException('承認済みの月次勤怠のみ締めることができます。');
        }

        $this->weeklyOvertimeAllocationGuard->ensureAllocated($month->user_id, $month->year_month);

        AttendanceMonthAggregate::retrieve($month->id)->close($command->closedByUserId)->persist();

        return AttendanceMonth::query()->findOrFail($command->attendanceMonthId);
    }
}
