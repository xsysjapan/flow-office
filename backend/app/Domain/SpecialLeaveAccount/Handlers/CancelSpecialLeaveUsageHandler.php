<?php

namespace App\Domain\SpecialLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Commands\CancelSpecialLeaveUsage;

/**
 * 申請の差戻し・取消に伴い消化記録を取り消す(確定済みなら充当を解除して残数を戻す)。
 *
 * viaReactor=true のとき、対応する消化記録が無い(差戻し前に消化記録を持たない申請など)・既に取消済みなら何もしない(冪等)。
 *
 * @implements CommandHandler<CancelSpecialLeaveUsage>
 */
class CancelSpecialLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof CancelSpecialLeaveUsage);

        $aggregate = SpecialLeaveAccountAggregate::retrieve(SpecialLeaveAccountAggregate::streamIdFor($command->userId));

        $usageId = $aggregate->usageIdForRequest($command->requestId);

        if ($usageId === null) {
            if ($command->viaReactor) {
                return null;
            }

            throw new DomainRuleException("特別休暇申請 [{$command->requestId}] に対応する消化記録が存在しません。");
        }

        if ($command->viaReactor && $aggregate->usageStatus($usageId) === 'cancelled') {
            return null;
        }

        $aggregate->cancelUsage($usageId, $command->reason)->persist();

        return null;
    }
}
