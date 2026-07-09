<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\CloseAttendanceMonth;
use App\Domain\Attendance\Events\AttendanceMonthClosed;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use Illuminate\Support\Carbon;

/**
 * UC-A011: 管理部が月次勤怠を締める。締め後は日次勤怠をロックする。
 *
 * @implements CommandHandler<CloseAttendanceMonth>
 */
class CloseAttendanceMonthHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof CloseAttendanceMonth);

        $month = AttendanceMonth::query()->findOrFail($command->attendanceMonthId);

        if ($month->status !== AttendanceMonthStatus::APPROVED) {
            throw new DomainRuleException('承認済みの月次勤怠のみ締めることができます。');
        }

        $now = Carbon::now();

        AttendanceDay::query()
            ->where('user_id', $month->user_id)
            ->where('work_date', 'like', "{$month->year_month}%")
            ->update(['locked_at' => $now]);

        $month->status = AttendanceMonthStatus::CLOSED;
        $month->closed_at = $now;
        $month->save();

        $this->eventStore->append(
            aggregateType: 'attendance_month',
            aggregateId: (string) $month->id,
            event: new AttendanceMonthClosed(
                attendanceMonthId: $month->id,
                closedByUserId: $command->closedByUserId,
            ),
        );

        return $month;
    }
}
