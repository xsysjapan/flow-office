<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\ConfirmCompensatoryLeaveUsage;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;

/**
 * 申請の承認に伴い消化記録を確定する(付与への充当。論点17により残数不足でも確定し未充当量を記録する)。
 *
 * viaReactor=true(承認からのReactor発行)で既に確定済みなら何もしない(冪等)。
 *
 * @implements CommandHandler<ConfirmCompensatoryLeaveUsage>
 */
class ConfirmCompensatoryLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ConfirmCompensatoryLeaveUsage);

        $aggregate = CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId);

        $usageId = $aggregate->usageIdForRequest($command->requestId);

        if ($usageId === null) {
            throw new DomainRuleException("代休申請 [{$command->requestId}] に対応する消化記録が存在しません。");
        }

        if ($command->viaReactor && $aggregate->usageStatus($usageId) === 'confirmed') {
            return null;
        }

        $aggregate->confirmUsage($usageId)->persist();

        return null;
    }
}
