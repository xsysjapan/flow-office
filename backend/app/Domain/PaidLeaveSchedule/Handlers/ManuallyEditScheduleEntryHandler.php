<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry;
use Illuminate\Support\Carbon;

/**
 * @implements CommandHandler<ManuallyEditScheduleEntry>
 */
class ManuallyEditScheduleEntryHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ManuallyEditScheduleEntry);

        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($command->userId))
            ->manuallyEditEntry(
                entryId: $command->entryId,
                category: $command->category,
                candidateGrantDays: $command->candidateGrantDays,
                reason: $command->reason,
                byUserId: $command->operatorUserId,
                at: Carbon::now()->toIso8601String(),
            )
            ->persist();

        return null;
    }
}
