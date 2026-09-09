<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeaveAccount\Commands\CancelPaidLeaveUsage;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveUsage;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;

/**
 * 有給申請を取り消す。対象日の勤怠(attendance_days.work_type)は申請時点
 * (RequestPaidLeaveHandler)で既に反映されているため、提出中(未承認)・承認済みの
 * どちらの取消でも巻き戻しが必要(承認済みの場合はさらに消化済みのpaid_leave_grantへの
 * 反映(残数を戻す)も伴う。ApprovePaidLeaveRequestHandlerの反対の操作)。月次勤怠が
 * 既に確定(締め)済みの場合は取消できない(AttendanceEditGuard::assertMutableが
 * 同じ基準で他の編集操作をブロックするのと同様)。
 *
 * Phase 5(cutover)により、旧`PaidLeaveGrantAggregate::reverseUsage`は廃止し、
 * `App\Domain\PaidLeaveAccount\Commands\CancelPaidLeaveUsage`を発行するだけにした
 * (Allocation解除・Grant残数の復元は`PaidLeaveAccountAggregate`内部で行う)。
 * 未承認・承認済みのどちらの取消でも同じ`CancelPaidLeaveUsage`で一貫して処理できる
 * (新ドメインのUsageは申請時点で必ず作成され、承認前でも取消可能なため)。
 * 起点のworkflow_requestがまだ提出中(未承認)の場合、旧
 * `CancelWorkflowRequestOnPaidLeaveRequestCancelledReactor`が担っていた
 * `CancelWorkflowRequest`の発行もこのHandlerへ統合した。
 *
 * @implements CommandHandler<CancelPaidLeaveRequest>
 */
class CancelPaidLeaveRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
        private readonly CommandBus $commandBus,
    ) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof CancelPaidLeaveRequest);

        $request = PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);

        if (! $command->isAdminAction && $request->user_id !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の有給申請のみ取消できます。');
        }

        if (! in_array($request->status, [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::APPROVED], true)) {
            throw new DomainRuleException('提出済みまたは承認済みの有給申請のみ取消できます。');
        }

        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->first();

        $this->guard->assertMutable($day, $request->user_id, $request->target_date->toDateString());

        $usageId = PaidLeaveUsage::query()
            ->where('paid_leave_request_id', $request->id)
            ->where('cancelled', false)
            ->value('usage_id');

        if ($usageId !== null) {
            $this->commandBus->dispatch(new CancelPaidLeaveUsage(
                userId: $request->user_id,
                usageId: $usageId,
                cancelledByUserId: $command->cancelledByUserId,
                reason: $command->isAdminAction ? '管理者による有給申請の取消' : '本人による有給申請の取消',
            ));
        }

        if ($day !== null) {
            $day->refresh();
            $day->work_type = null;
            // 全休の場合、申請時に打刻無しでもclocked_out扱いにしていたため
            // (RequestPaidLeaveHandler::reflectOnAttendanceDay)、実際の打刻が無いままなら
            // その状態も巻き戻す。半休・時間休は実働時間があるため打刻由来のステータスを
            // そのまま維持する。
            if ($day->actual_start_at === null && $day->actual_end_at === null) {
                $day->status = AttendanceDayStatus::NOT_STARTED;
            }
            $day->save();

            $calculation = $this->calculator->calculate(
                $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'calendarEntry.workStyle'),
            );

            AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();
        }

        // 未承認(submitted)のまま取消された場合、起点となったworkflow_requestが提出中のまま
        // 取り残され、統合申請一覧に承認待ちとして残り続けてしまう。旧
        // `CancelWorkflowRequestOnPaidLeaveRequestCancelledReactor`の処理をここへ統合する。
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST)
            ->where('subject_id', $request->id)
            ->whereIn('status', WorkflowRequestStatus::cancellable())
            ->first();

        if ($workflowRequest !== null) {
            $this->commandBus->dispatch(new CancelWorkflowRequest(
                workflowRequestId: $workflowRequest->id,
                cancelledByUserId: $command->cancelledByUserId,
                reason: '有給申請が取り消されました。',
            ));
        }

        return PaidLeaveRequest::query()->findOrFail($request->id);
    }
}
