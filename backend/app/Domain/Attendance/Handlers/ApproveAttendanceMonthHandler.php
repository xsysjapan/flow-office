<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\ApproveAttendanceMonth;
use App\Domain\Attendance\Events\AttendanceMonthApproved;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use Illuminate\Support\Carbon;

/**
 * UC-A009: 承認者が月次勤怠を承認する。
 *
 * @implements CommandHandler<ApproveAttendanceMonth>
 */
class ApproveAttendanceMonthHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

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

        $month->status = AttendanceMonthStatus::APPROVED;
        $month->approved_at = Carbon::now();
        $month->save();

        $this->eventStore->append(
            aggregateType: 'attendance_month',
            aggregateId: (string) $month->id,
            event: new AttendanceMonthApproved(
                attendanceMonthId: $month->id,
                approvedByUserId: $command->approvedByUserId,
            ),
        );

        SendTeamsNotificationJob::dispatch(
            title: '月次勤怠が承認されました',
            summary: "{$month->year_month} の月次勤怠が承認されました。バックオフィス確認対象になります。",
            detailUrl: null,
        );

        return $month;
    }
}
