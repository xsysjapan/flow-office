<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use Illuminate\Support\Str;

/**
 * @implements CommandHandler<DesignatePaidLeaveUsage>
 */
class DesignatePaidLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof DesignatePaidLeaveUsage);

        $usageId = (string) Str::uuid();

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->designateUsage(
                usageId: $usageId,
                workflowRequestId: $command->workflowRequestId,
                attendanceDayId: $command->attendanceDayId,
                usedOn: $command->usedOn,
                usedDays: $command->usedDays,
                usageType: $command->usageType,
                paidLeaveRequestId: $command->paidLeaveRequestId,
                approverUserId: $command->approverUserId,
                reason: $command->reason,
                requestGroupId: $command->requestGroupId,
                hours: $command->hours,
            )
            ->persist();

        return $usageId;
    }
}
