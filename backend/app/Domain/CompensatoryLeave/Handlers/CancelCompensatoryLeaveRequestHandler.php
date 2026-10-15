<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveRequest;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;

/**
 * 代休申請を取り消す。申請の集約へ取消を記録するだけで、消化記録の取消(承認済みは充当の解除)は
 * 残数側、勤怠の解除は勤怠側のReactorが行う(原則15)。締め済みの日の拒否は勤怠側のReactorが例外を投げて表す。
 *
 * - 申請者本人チェックは利用者の操作(viaReactor=false・管理者でない)のときだけ行う。
 * - viaReactor=true(ワークフローの取消からのReactor発行)は既に取消済みなら何もしない(冪等)。
 * - 承認済みのワークフローは状態を変えない(Workflow側の取消Reactorが取消可能な状態のものだけ取り消す)。
 *
 * @implements CommandHandler<CancelCompensatoryLeaveRequest>
 */
class CancelCompensatoryLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof CancelCompensatoryLeaveRequest);

        $aggregate = CompensatoryLeaveRequestAggregate::retrieve($command->compensatoryLeaveRequestId);

        if ($command->viaReactor && $aggregate->isCancelled()) {
            return null;
        }

        if (! $command->viaReactor && ! $command->isAdminAction && $aggregate->userId() !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の代休申請のみ取消できます。');
        }

        $aggregate->cancel($command->cancelledByUserId)->persist();

        return null;
    }
}
