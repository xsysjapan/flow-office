<?php

namespace App\Domain\SpecialLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Commands\MigrateSpecialLeaveAccount;

/**
 * 既存の特別休暇データを口座へ引き継ぐ(空の口座に一度だけ。検証は口座集約が行う)。
 *
 * @implements CommandHandler<MigrateSpecialLeaveAccount>
 */
class MigrateSpecialLeaveAccountHandler implements CommandHandler
{
    public function handle(Command $command): void
    {
        assert($command instanceof MigrateSpecialLeaveAccount);

        SpecialLeaveAccountAggregate::retrieve(SpecialLeaveAccountAggregate::streamIdFor($command->userId))
            ->migrate($command->grants, $command->usages, $command->userId)
            ->persist();
    }
}
