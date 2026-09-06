<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeave\Commands\ReturnPaidLeaveRequest;
use App\Jobs\SendNotificationJob;
use App\Models\PaidLeaveRequest;
use App\Models\User;
use App\Support\FrontendUrl;

/**
 * Phase 5(cutover)により、旧`PaidLeaveRequestAggregate::returnRequest`は廃止した。
 * `paid_leave_requests.status`自体の更新は、この差戻しが必ずworkflow_request経由で
 * 発生すること(`PaidLeaveReturnOnWorkflowRequestReturnedReactor`参照)を利用し、
 * `App\Domain\PaidLeaveAccount\Projectors\PaidLeaveUsageAllocationProjector::onWorkflowRequestReturned`が
 * Workflowドメインの`WorkflowRequestReturned`イベントを直接購読して行う
 * (差戻しは承認前の状態のためGrant/Usageには一切影響しない。旧Handlerの挙動と同じ
 * ―対象日の勤怠・paid_leave_usagesは変更しない。既存のcutover前挙動をそのまま保つ判断)。
 * このHandlerの役目は、Reactor経由で二重に来た場合の検証と通知のみに残す。
 *
 * @implements CommandHandler<ReturnPaidLeaveRequest>
 */
class ReturnPaidLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof ReturnPaidLeaveRequest);

        // 差戻し可否(提出済みであること・指定承認者であること)は、このHandlerを呼び出す
        // 起点である`ReturnWorkflowRequestHandler`が既にworkflow_request側で検証済み
        // (`PaidLeaveReturnOnWorkflowRequestReturnedReactor`経由でここに来る時点でイベントは
        // 既に記録されているため、ここで同じ検証をやり直すとProjector実行順序次第で
        // 二重チェックが不整合を起こしうる。クラスdoc参照)。
        $request = PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);

        $applicant = User::find($request->user_id);
        if ($applicant !== null) {
            SendNotificationJob::enqueue(
                recipient: $applicant,
                title: '有給申請の差戻し',
                summary: "{$request->target_date->toDateString()} の有給申請が差し戻されました: {$command->comment}",
                detailUrl: FrontendUrl::path('/paid-leave/history'),
            );
        }

        return $request->fresh();
    }
}
