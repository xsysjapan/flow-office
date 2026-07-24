<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\CloseAttendanceMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use Illuminate\Support\Carbon;

/**
 * UC-A011: 管理部が月次勤怠を締める。締め後は日次勤怠をロックする。
 *
 * 注意: 対象月に属する全attendance_daysのlocked_at一括更新は、この移行(docs/29)以前から
 * イベントを経由しない直接更新のままになっている(1回の締めで数十件のattendance_dayに
 * またがるため、attendance_day集約の個々のイベントには分解していない)。この移行スコープは
 * attendance_month集約自体のspatie化であり、この既存の挙動は変更していない。
 *
 * @implements CommandHandler<CloseAttendanceMonth>
 */
class CloseAttendanceMonthHandler implements CommandHandler
{
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

        AttendanceMonthAggregate::retrieve($month->id)->close($command->closedByUserId)->persist();

        return AttendanceMonth::query()->findOrFail($command->attendanceMonthId);
    }
}
