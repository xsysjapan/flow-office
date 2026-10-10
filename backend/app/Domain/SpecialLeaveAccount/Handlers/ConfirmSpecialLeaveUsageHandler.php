<?php

namespace App\Domain\SpecialLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Commands\ConfirmSpecialLeaveUsage;

/**
 * 申請の承認に伴い消化記録を確定する(付与への充当。論点17により残数不足でも確定し未充当量を記録する)。
 *
 * @implements CommandHandler<ConfirmSpecialLeaveUsage>
 */
class ConfirmSpecialLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): void
    {
        assert($command instanceof ConfirmSpecialLeaveUsage);

        $aggregate = SpecialLeaveAccountAggregate::retrieve(SpecialLeaveAccountAggregate::streamIdFor($command->userId));

        $usageId = $aggregate->usageIdForRequest($command->requestId);

        if ($usageId === null) {
            throw new DomainRuleException("特別休暇申請 [{$command->requestId}] に対応する消化記録が存在しません。");
        }

        // viaReactor=true: 既に確定済みなら何もしない。
        if ($command->viaReactor && $aggregate->usageStatus($usageId) === 'confirmed') {
            return;
        }

        $aggregate->confirmUsage($usageId, $command->requiresGrant)->persist();
    }
}
