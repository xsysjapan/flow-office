<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\MigrateCompensatoryLeaveAccount;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;

/**
 * 本変更前の代休の付与・消化記録を利用者単位の口座へ引き継ぐ(運用コマンドが発行。口座が空のときだけ可能)。
 *
 * @implements CommandHandler<MigrateCompensatoryLeaveAccount>
 */
class MigrateCompensatoryLeaveAccountHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof MigrateCompensatoryLeaveAccount);

        CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId)
            ->migrate($command->grants, $command->usages)
            ->persist();

        return null;
    }
}
