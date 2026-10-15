<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeave\Commands\ReturnPaidLeaveRequest;
use App\Domain\PaidLeaveRequest\Aggregates\PaidLeaveRequestAggregate;
use App\Models\PaidLeaveRequest;

/**
 * 有給申請を差し戻す。有給申請の集約へ差戻しを記録する。
 *
 * 差戻しの通知はワークフロー側(ReturnWorkflowRequestHandler)が1回だけ送る(二重通知の解消)。
 * 差し戻された申請の消化記録の取消は、`paid_leave_request.returned`を受けた有給口座のReactorが行う。
 * viaReactor=true で既に差戻し中なら何もしない(冪等)。
 *
 * @implements CommandHandler<ReturnPaidLeaveRequest>
 */
class ReturnPaidLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof ReturnPaidLeaveRequest);

        $aggregate = PaidLeaveRequestAggregate::retrieve($command->paidLeaveRequestId);

        if ($command->viaReactor && $aggregate->isReturned()) {
            return PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);
        }

        $aggregate->return($command->returnedByUserId, $command->comment)->persist();

        return PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);
    }
}
