<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\RevertApprovedAttendanceMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\WorkflowRequest;
use Illuminate\Support\Carbon;

/**
 * 救済コマンド: バックオフィス担当者専用。汎用申請ワークフロー「勤怠確定取消依頼」
 * (request_types.code = attendance_confirmation_revert)が承認され、バックオフィス担当者が
 * 処理する過程で実行する。承認済みの月次勤怠の確定を取り消し、未提出状態に戻す。
 * 権限は`permission:attendance.confirmation_revert`(バックオフィス担当ロールのみ付与)で
 * 保証する。実行後は通常の日次編集→再提出→再承認フローに乗る。
 *
 * @implements CommandHandler<RevertApprovedAttendanceMonth>
 */
class RevertApprovedAttendanceMonthHandler implements CommandHandler
{
    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof RevertApprovedAttendanceMonth);

        $month = AttendanceMonth::query()->findOrFail($command->attendanceMonthId);

        if ($month->status !== AttendanceMonthStatus::APPROVED) {
            throw new DomainRuleException('承認済みの月次勤怠のみ確定を取り消すことができます。');
        }

        WorkflowRequest::query()->findOrFail($command->workflowRequestId);

        $periodStart = "{$month->year_month}-01";
        $periodEnd = Carbon::parse($periodStart)->endOfMonth()->toDateString();

        AttendanceMonthAggregate::retrieve($month->id)
            ->revertConfirmation(
                userId: $month->user_id,
                revertedByUserId: $command->revertedByUserId,
                reason: $command->reason,
                workflowRequestId: $command->workflowRequestId,
                periodStartDate: $periodStart,
                periodEndDate: $periodEnd,
            )
            ->persist();

        return AttendanceMonth::query()->findOrFail($command->attendanceMonthId);
    }
}
