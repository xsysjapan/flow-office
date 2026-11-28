<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Commands\SetUserUsageStartDate;
use App\Domain\UserManagement\Services\FieldAuthorityService;
use App\Models\User;

/**
 * @implements CommandHandler<SetUserUsageStartDate>
 */
class SetUserUsageStartDateHandler implements CommandHandler
{
    public function __construct(private FieldAuthorityService $authorities) {}

    public function handle(Command $command): User
    {
        assert($command instanceof SetUserUsageStartDate);

        $this->authorities->assertLocallyEditable(['usage_start_date']);
        $user = User::query()->findOrFail($command->userId);

        UserAggregate::retrieve($user->id)->setUsageStartDate($command->usageStartDate, $command->changedByUserId)->persist();

        return $user->refresh();
    }
}
