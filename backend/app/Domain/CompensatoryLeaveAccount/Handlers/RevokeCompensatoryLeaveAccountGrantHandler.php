<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\RevokeCompensatoryLeaveAccountGrant;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;

/**
 * 管理者が代休付与を直接取り消す(未使用の確定済みのみ。判定は口座集約の`cancelGrant`)。
 *
 * @implements CommandHandler<RevokeCompensatoryLeaveAccountGrant>
 */
class RevokeCompensatoryLeaveAccountGrantHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof RevokeCompensatoryLeaveAccountGrant);

        CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId)
            ->cancelGrant($command->grantId, $command->cancelledByUserId, $command->reason)
            ->persist();

        return null;
    }
}
