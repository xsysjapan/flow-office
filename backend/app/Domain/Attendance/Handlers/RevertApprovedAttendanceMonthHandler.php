<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\RevertApprovedAttendanceMonth;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Jobs\SendNotificationJob;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Support\FrontendUrl;
use Illuminate\Support\Carbon;

/**
 * 救済コマンド: 承認済みの月次勤怠の確定を取り消し、未提出状態に戻す。確定取消の権限
 * (`permission:attendance.confirmation_revert`)を持つ担当者が、申請なし(workflowRequestIdがnull)
 * または勤怠確定取消依頼(request_types.code = attendance_confirmation_revert)を根拠に実行する。
 * 保存後、月の持ち主へ理由つきで通知する。実行後は通常の日次編集→再提出→再承認フローに乗る。
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

        if ($command->workflowRequestId !== null) {
            WorkflowRequest::query()->findOrFail($command->workflowRequestId);
        }

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

        $owner = User::query()->find($month->user_id);
        if ($owner !== null) {
            SendNotificationJob::enqueue(
                recipient: $owner,
                title: '月次勤怠が未提出に戻されました',
                summary: "{$month->year_month}の月次勤怠が未提出に戻されました。理由: {$command->reason}",
                detailUrl: FrontendUrl::path("/attendance/months/{$month->year_month}"),
            );
        }

        return AttendanceMonth::query()->findOrFail($command->attendanceMonthId);
    }
}
