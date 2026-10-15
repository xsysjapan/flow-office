<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\CancelSpecialLeaveRequest;
use App\Models\SpecialLeaveRequest;

/**
 * 特別休暇申請を取り消す。申請の集約へ取消を記録するだけで、消化記録の取消(承認済みは充当の解除)は
 * 残数側、勤怠の解除は勤怠側のReactorが行う(原則15)。締め済みの日の拒否は勤怠側のReactorが例外を投げて表す。
 *
 * - 申請者本人チェックは利用者の操作(viaReactor=false・管理者でない)のときだけ行う。
 * - viaReactor=true(ワークフローの取消からのReactor発行)は既に取消済みなら何もしない(冪等)。
 * - 承認済みのワークフローは状態を変えない(Workflow側の取消Reactorが取消可能な状態のものだけ取り消す)。
 *
 * @implements CommandHandler<CancelSpecialLeaveRequest>
 */
class CancelSpecialLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof CancelSpecialLeaveRequest);

        $aggregate = SpecialLeaveRequestAggregate::retrieve($command->specialLeaveRequestId);

        if ($command->viaReactor && $aggregate->isCancelled()) {
            return SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);
        }

        if (! $command->viaReactor && ! $command->isAdminAction && $aggregate->userId() !== $command->cancelledByUserId) {
            throw new DomainRuleException('自分の特別休暇申請のみ取消できます。');
        }

        $aggregate->cancel($command->cancelledByUserId)->persist();

        return SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);
    }
}
