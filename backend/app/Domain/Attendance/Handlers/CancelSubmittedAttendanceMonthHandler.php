<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\CancelSubmittedAttendanceMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use Illuminate\Support\Carbon;

/**
 * UC-A010関連: 申請者自身が月次勤怠申請(workflow_request)を取り消したら、対象の月次勤怠を
 * 未提出へ戻す。取り消し可能な workflow_request のステータス(draft/submitted/returned)に
 * 対応する月次勤怠ステータスは submitted/returned のみ(draftの間はまだ月次勤怠自体が
 * 提出されておらず、この集約に対する操作が発生しない)。
 *
 * @implements CommandHandler<CancelSubmittedAttendanceMonth>
 */
class CancelSubmittedAttendanceMonthHandler implements CommandHandler
{
    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof CancelSubmittedAttendanceMonth);

        $month = AttendanceMonth::query()->findOrFail($command->attendanceMonthId);

        if (! in_array($month->status, [AttendanceMonthStatus::SUBMITTED, AttendanceMonthStatus::RETURNED], true)) {
            throw new DomainRuleException('提出済みまたは差戻し済みの月次勤怠のみ取り消せます。');
        }

        $periodStart = "{$month->year_month}-01";
        $periodEnd = Carbon::parse($periodStart)->endOfMonth()->toDateString();

        AttendanceMonthAggregate::retrieve($month->id)
            ->cancelSubmission($month->user_id, $command->cancelledByUserId, $periodStart, $periodEnd)
            ->persist();

        return AttendanceMonth::query()->findOrFail($command->attendanceMonthId);
    }
}
