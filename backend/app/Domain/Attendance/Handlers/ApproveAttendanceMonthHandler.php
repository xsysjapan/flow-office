<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\ApproveAttendanceMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;

/**
 * UC-A009: 承認者が月次勤怠を承認する。
 *
 * @implements CommandHandler<ApproveAttendanceMonth>
 */
class ApproveAttendanceMonthHandler implements CommandHandler
{
    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof ApproveAttendanceMonth);

        $month = AttendanceMonth::query()->findOrFail($command->attendanceMonthId);

        if ($month->status !== AttendanceMonthStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの月次勤怠のみ承認できます。');
        }

        if ($month->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        AttendanceMonthAggregate::retrieve($month->id)->approve($command->approvedByUserId)->persist();

        // 承認通知はApproveWorkflowRequestHandler(WorkflowRequestNotificationContent)に
        // 一本化しているため、ここでは送らない。
        return AttendanceMonth::query()->findOrFail($command->attendanceMonthId);
    }
}
