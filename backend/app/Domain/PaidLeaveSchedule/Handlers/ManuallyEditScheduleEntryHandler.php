<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry;

/**
 * @implements CommandHandler<ManuallyEditScheduleEntry>
 */
class ManuallyEditScheduleEntryHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ManuallyEditScheduleEntry);

        PaidLeaveScheduleAggregate::retrieve($command->userId)
            ->manuallyEditScheduleEntry(
                scheduleEntryId: $command->scheduleEntryId,
                changes: $command->changes,
                reason: $command->reason,
                operatorUserId: $command->operatorUserId,
            )
            ->persist();

        return null;
    }
}
