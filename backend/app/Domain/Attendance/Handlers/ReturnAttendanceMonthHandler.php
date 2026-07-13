<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\ReturnAttendanceMonth;
use App\Domain\Attendance\Events\AttendanceMonthReturned;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Jobs\SendTeamsNotificationJob;
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
    public function __construct(private readonly EventStore $eventStore) {}

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

        $month->status = AttendanceMonthStatus::RETURNED;
        $month->returned_at = Carbon::now();
        $month->save();

        $this->eventStore->append(
            aggregateType: 'attendance_month',
            aggregateId: (string) $month->id,
            event: new AttendanceMonthReturned(
                attendanceMonthId: $month->id,
                returnedByUserId: $command->returnedByUserId,
                comment: $command->comment,
            ),
        );

        SendTeamsNotificationJob::enqueue(
            title: '月次勤怠が差戻されました',
            summary: "{$month->year_month} の月次勤怠が差し戻されました: {$command->comment}",
            detailUrl: null,
        );

        return $month;
    }
}
