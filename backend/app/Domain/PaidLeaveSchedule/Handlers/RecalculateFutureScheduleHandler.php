<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;

/**
 * @implements CommandHandler<RecalculateFutureSchedule>
 */
class RecalculateFutureScheduleHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof RecalculateFutureSchedule);

        PaidLeaveScheduleAggregate::retrieve($command->userId)
            ->recalculateFutureSchedule($command->candidates, $command->reason, $command->overrideManualEdits, $command->includeGrantedEntries)
            ->persist();

        return null;
    }
}
