<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use Illuminate\Support\Carbon;

/**
 * UC-A010: 承認者が月次勤怠を差戻しする。
 *
 * @implements CommandHandler<ReturnAttendanceMonth>
 */
class ReturnAttendanceMonthHandler implements CommandHandler
{
    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof ReturnAttendanceMonth);

        $month = AttendanceMonth::query()->findOrFail($command->attendanceMonthId);

        if ($month->status !== AttendanceMonthStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの月次勤怠のみ差戻しできます。');
        }

        if ($month->approver_user_id !== $command->returnedByUserId) {
            throw new DomainRuleException('指定された承認者のみ差戻しできます。');
        }

        $periodStart = "{$month->year_month}-01";
        $periodEnd = Carbon::parse($periodStart)->endOfMonth()->toDateString();

        AttendanceMonthAggregate::retrieve($month->id)
            ->returnToApplicant($month->user_id, $command->returnedByUserId, $command->comment, $periodStart, $periodEnd)
            ->persist();

        // 差戻し通知はReturnWorkflowRequestHandler(WorkflowRequestNotificationContent)に
        // 一本化しているため、ここでは送らない。
        return AttendanceMonth::query()->findOrFail($command->attendanceMonthId);
    }
}
