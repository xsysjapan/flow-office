<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Rebuild\LeaveUsageId;
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

        $aggregate = PaidLeaveAccountAggregate::retrieve($command->userId);

        // viaReactor=true: 同じ有給申請IDの有効な消化記録が既にあれば何もしない(既存のusageIdを返す)。
        if ($command->viaReactor
            && $command->paidLeaveRequestId !== null
            && $aggregate->hasActiveUsageForRequest($command->paidLeaveRequestId)
        ) {
            return (string) $aggregate->usageIdForRequest($command->paidLeaveRequestId);
        }

        // 有給申請の消化記録は申請IDと再提出の回数で決まるID(論点18。作り直しの変換と同じ採番)。
        $usageId = $command->paidLeaveRequestId !== null
            ? LeaveUsageId::for('paid_leave', $command->paidLeaveRequestId, $aggregate->designationCountForRequest($command->paidLeaveRequestId))
            : (string) Str::uuid();

        $aggregate
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
