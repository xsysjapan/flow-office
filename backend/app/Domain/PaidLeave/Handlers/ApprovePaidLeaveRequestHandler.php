<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Models\AttendanceDay;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveUsage;

/**
 * UC-P004: 有給を承認する。対象日の勤怠(attendance_days.work_type)への反映は
 * 申請時点(RequestPaidLeaveHandler)で既に行われているため、承認時に行うのは
 * 有効期限が近い付与分からの消化(grant消費の確定)と、それに伴う日次集計
 * (paid_leave_minutes/paid_leave_days)の再計算のみ。残数が不足していても
 * (マイナスになっても)承認自体は成立させる(RequestPaidLeaveHandler冒頭のコメント参照。
 * 残数は承認済み分のみで計測する方針のため、ここで消化できなかった分は単に記録しない)。
 *
 * Phase 5(cutover)により、旧`PaidLeaveGrantAggregate`によるFIFO消化プランの手組みは廃止し、
 * `App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage`を発行するだけにした。
 * どのGrantから消化するか(消化順・複数Grantへの分割)は`PaidLeaveAccountAggregate`内部の
 * `AllocationPlanner`が判定する(Handlerはこの判定にEloquent Projectionを問い合わせない。
 * docs/changesets/20260906-paid-leave-domain-redesign/spec.md 論点1/2)。
 * `paid_leave_requests.status`自体の更新は
 * `App\Domain\PaidLeaveAccount\Projectors\PaidLeaveRequestProjector`が
 * `PaidLeaveUsageConfirmed`イベントから行う。
 *
 * @implements CommandHandler<ApprovePaidLeaveRequest>
 */
class ApprovePaidLeaveRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly CommandBus $commandBus,
    ) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof ApprovePaidLeaveRequest);

        $request = PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);

        if ($request->status !== PaidLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの有給申請のみ承認できます。');
        }

        // approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による承認不要の即時確定
        // (PaidLeaveController::storeRequest参照)であり、承認者チェックそのものを行わない。
        if ($command->approvedByUserId !== null && $request->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        // 申請時点(RequestPaidLeaveHandler)で対象日の勤怠は必ず作成済みのため、
        // ここでは参照するのみ(存在しない場合は不整合として例外にする)。
        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->firstOrFail();

        $usageId = PaidLeaveUsage::query()
            ->where('paid_leave_request_id', $request->id)
            ->where('cancelled', false)
            ->value('usage_id');

        if ($usageId !== null) {
            $this->commandBus->dispatch(new ConfirmPaidLeaveUsage(
                userId: $request->user_id,
                usageId: $usageId,
                confirmedByUserId: $command->approvedByUserId,
            ));
        }

        $calculation = $this->calculator->calculate(
            $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'calendarEntry.workStyle'),
        );
        AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();

        return PaidLeaveRequest::query()->findOrFail($request->id);
    }
}
