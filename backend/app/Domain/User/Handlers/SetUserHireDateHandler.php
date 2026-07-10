<?php

namespace App\Domain\User\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\User\Commands\SetUserHireDate;
use App\Domain\User\Events\UserHireDateSet;
use App\Models\User;

/**
 * @implements CommandHandler<SetUserHireDate>
 */
class SetUserHireDateHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): User
    {
        assert($command instanceof SetUserHireDate);

        $user = User::query()->findOrFail($command->userId);
        $user->hire_date = $command->hireDate;
        $user->save();

        $this->eventStore->append(
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            event: new UserHireDateSet(
                userId: $user->id,
                hireDate: $command->hireDate,
                changedByUserId: $command->changedByUserId,
            ),
        );

        return $user;
    }
}
