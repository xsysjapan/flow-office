<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeaveRequest\Aggregates\PaidLeaveRequestAggregate;
use App\Models\PaidLeaveRequest;

/**
 * 有給申請を取り消す。有給申請の集約へ取消を記録するだけで、消化記録の取消(残数の復元)は
 * `paid_leave_request.cancelled`を受けた有給口座のReactorが、申請中ならワークフローの取消は
 * Workflow側のReactorが行う(原則15)。
 *
 * - 申請者本人(管理者取消は管理者)のみ取り消せる。viaReactor=true(ワークフロー側の取消からのReactor)は
 *   本人チェックを行わず、既に取消済みなら何もしない(冪等)。
 * - 承認済みの移行前申請の取消拒否は集約のルール(PaidLeaveRequestAggregate::cancel)。
 *
 * @implements CommandHandler<CancelPaidLeaveRequest>
 */
class CancelPaidLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof CancelPaidLeaveRequest);

        $aggregate = PaidLeaveRequestAggregate::retrieve($command->paidLeaveRequestId);

        if ($command->viaReactor && $aggregate->isCancelled()) {
            return PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);
        }

        if (! $command->viaReactor
            && ! $command->isAdminAction
            && $aggregate->userId() !== $command->cancelledByUserId
        ) {
            throw new DomainRuleException('自分の有給申請のみ取消できます。');
        }

        $reason = match (true) {
            $command->viaReactor => 'ワークフローの取消に伴う有給申請の取消',
            $command->isAdminAction => '管理者による有給申請の取消',
            default => '本人による有給申請の取消',
        };

        $aggregate->cancel($command->cancelledByUserId, $reason)->persist();

        return PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);
    }
}
