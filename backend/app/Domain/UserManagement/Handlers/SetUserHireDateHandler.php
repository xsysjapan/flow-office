<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Domain\UserManagement\Services\FieldAuthorityService;
use App\Models\User;

/**
 * @implements CommandHandler<SetUserHireDate>
 */
class SetUserHireDateHandler implements CommandHandler
{
    public function __construct(private FieldAuthorityService $authorities) {}

    public function handle(Command $command): User
    {
        assert($command instanceof SetUserHireDate);

        $this->authorities->assertLocallyEditable(['hire_date']);
        $user = User::query()->findOrFail($command->userId);
        if ($user->termination_date !== null && $user->termination_date->toDateString() < $command->hireDate) {
            throw new DomainRuleException('入社日は退社日以前の日付を指定してください。');
        }

        UserAggregate::retrieve($user->id)->setHireDate($command->hireDate, $command->changedByUserId)->persist();

        return $user->refresh();
    }
}
