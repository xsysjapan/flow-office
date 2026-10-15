<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeave\Commands\ResubmitPaidLeaveRequest;
use App\Domain\PaidLeaveRequest\Aggregates\PaidLeaveRequestAggregate;
use App\Models\PaidLeaveRequest;

/**
 * 差戻された有給申請を同じ内容のまま再提出する(論点8。設計書docs/09 再提出時は新規の消化記録)。
 * 申請中に戻し、新しい消化記録の作成は`paid_leave_request.resubmitted`を受けた有給口座のReactorが行う。
 *
 * viaReactor=true(ワークフローの提出からのReactor)で、既に申請中(最初の提出)なら何もしない(冪等)。
 *
 * @implements CommandHandler<ResubmitPaidLeaveRequest>
 */
class ResubmitPaidLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof ResubmitPaidLeaveRequest);

        $aggregate = PaidLeaveRequestAggregate::retrieve($command->paidLeaveRequestId);

        if ($command->viaReactor && $aggregate->isSubmitted()) {
            return PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);
        }

        $aggregate->resubmit($command->resubmittedByUserId)->persist();

        return PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);
    }
}
