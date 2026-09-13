<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\EnsureFutureScheduleGenerated;

/**
 * @implements CommandHandler<EnsureFutureScheduleGenerated>
 */
class EnsureFutureScheduleGeneratedHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof EnsureFutureScheduleGenerated);

        PaidLeaveScheduleAggregate::retrieve($command->userId)
            ->ensureFutureScheduleGenerated($command->candidates)
            ->persist();

        return null;
    }
}
