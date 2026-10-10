<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\CancelCompensatoryLeaveUsage;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;

/**
 * 申請の差戻し・取消に伴い消化記録を取り消す(承認済みは充当を解除し残数を戻す)。
 *
 * viaReactor=true のとき、消化記録が無い・既に取消済みなら何もしない(冪等)。
 *
 * @implements CommandHandler<CancelCompensatoryLeaveUsage>
 */
class CancelCompensatoryLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof CancelCompensatoryLeaveUsage);

        $aggregate = CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId);

        $usageId = $aggregate->usageIdForRequest($command->requestId);

        if ($usageId === null) {
            if ($command->viaReactor) {
                return null;
            }

            throw new DomainRuleException("代休申請 [{$command->requestId}] に対応する消化記録が存在しません。");
        }

        if ($command->viaReactor && $aggregate->usageStatus($usageId) === 'cancelled') {
            return null;
        }

        $aggregate->cancelUsage($usageId, $command->reason)->persist();

        return null;
    }
}
