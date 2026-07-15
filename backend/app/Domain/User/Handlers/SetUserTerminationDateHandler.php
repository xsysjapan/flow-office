<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\User\Commands\SetUserTerminationDate;
use App\Domain\User\Events\UserTerminationDateSet;
use App\Models\User;

/** @implements CommandHandler<SetUserTerminationDate> */
class SetUserTerminationDateHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): User
    {
        assert($command instanceof SetUserTerminationDate);

        $user = User::query()->findOrFail($command->userId);
        if ($command->terminationDate !== null && $user->hire_date?->toDateString() > $command->terminationDate) {
            throw new DomainRuleException('退社日は入社日以降の日付を指定してください。');
        }

        $user->termination_date = $command->terminationDate;
        $user->save();

        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserTerminationDateSet(
                userId: $user->id,
                terminationDate: $command->terminationDate,
                changedByUserId: $command->changedByUserId,
            ),
        );

        return $user;
    }
}