<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Aggregates\UserAggregate;
use App\Domain\User\Commands\SetUserTerminationDate;
use App\Models\User;

/** @implements CommandHandler<SetUserTerminationDate> */
class SetUserTerminationDateHandler implements CommandHandler
{
    public function handle(Command $command): User
    {
        assert($command instanceof SetUserTerminationDate);

        $user = User::query()->findOrFail($command->userId);
        if ($command->terminationDate !== null && $user->hire_date?->toDateString() > $command->terminationDate) {
            throw new DomainRuleException('退社日は入社日以降の日付を指定してください。');
        }

        UserAggregate::retrieve($user->id)
            ->setTerminationDate($command->terminationDate, $command->changedByUserId)
            ->persist();

        return $user->refresh();
    }
}
