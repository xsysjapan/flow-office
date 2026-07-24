<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Jobs\SendNotificationJob;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\User;

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

        AttendanceMonthAggregate::retrieve($month->id)
            ->returnToApplicant($command->returnedByUserId, $command->comment)
            ->persist();

        $month = AttendanceMonth::query()->findOrFail($command->attendanceMonthId);

        $applicant = User::find($month->user_id);
        if ($applicant !== null) {
            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: '月次勤怠が差戻されました',
                summary: "{$month->year_month} の月次勤怠が差し戻されました: {$command->comment}",
                detailUrl: null,
            );
        }

        return $month;
    }
}
