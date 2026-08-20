<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\ReopenClosedAttendanceMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;

/**
 * 救済コマンド: 管理者専用。締め済みの月次勤怠の締めを取り消し、承認済み状態に戻す。
 * 権限は`permission:attendance.month_reopen`(管理者ロールのみ付与)で保証する。
 *
 * @implements CommandHandler<ReopenClosedAttendanceMonth>
 */
class ReopenClosedAttendanceMonthHandler implements CommandHandler
{
    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof ReopenClosedAttendanceMonth);

        $month = AttendanceMonth::query()->findOrFail($command->attendanceMonthId);

        if ($month->status !== AttendanceMonthStatus::CLOSED) {
            throw new DomainRuleException('締め済みの月次勤怠のみ締めを取り消すことができます。');
        }

        AttendanceMonthAggregate::retrieve($month->id)
            ->reopen($command->reopenedByUserId, $command->reason)
            ->persist();

        return AttendanceMonth::query()->findOrFail($command->attendanceMonthId);
    }
}
