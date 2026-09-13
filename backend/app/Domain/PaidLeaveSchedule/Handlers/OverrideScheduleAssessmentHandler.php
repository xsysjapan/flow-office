<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\OverrideScheduleAssessment;

/**
 * @implements CommandHandler<OverrideScheduleAssessment>
 */
class OverrideScheduleAssessmentHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof OverrideScheduleAssessment);

        PaidLeaveScheduleAggregate::retrieve($command->userId)
            ->overrideScheduleAssessment(
                scheduleEntryId: $command->scheduleEntryId,
                finalResult: $command->finalResult,
                reason: $command->reason,
                operatorUserId: $command->operatorUserId,
            )
            ->persist();

        return null;
    }
}
