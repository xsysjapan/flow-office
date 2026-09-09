<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\OverrideScheduleAssessment;
use Illuminate\Support\Carbon;

/**
 * @implements CommandHandler<OverrideScheduleAssessment>
 */
class OverrideScheduleAssessmentHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof OverrideScheduleAssessment);

        PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($command->userId))
            ->overrideAssessment(
                entryId: $command->entryId,
                finalResult: $command->finalResult,
                reason: $command->reason,
                byUserId: $command->operatorUserId,
                at: Carbon::now()->toIso8601String(),
            )
            ->persist();

        return null;
    }
}
