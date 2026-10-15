<?php

namespace App\Domain\SpecialLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Rebuild\LeaveUsageId;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Commands\DesignateSpecialLeaveUsage;

/**
 * 申請に対応する消化記録を作成する(充当は行わない)。消化記録IDは発行して返す。
 *
 * @implements CommandHandler<DesignateSpecialLeaveUsage>
 */
class DesignateSpecialLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): string
    {
        assert($command instanceof DesignateSpecialLeaveUsage);

        $aggregate = SpecialLeaveAccountAggregate::retrieve(SpecialLeaveAccountAggregate::streamIdFor($command->userId));

        // viaReactor=true: 同じ申請の有効な消化記録が既にあれば何もしない(既存のusageIdを返す)。
        if ($command->viaReactor && $aggregate->hasActiveUsageForRequest($command->requestId)) {
            return (string) $aggregate->usageIdForRequest($command->requestId);
        }

        // 消化記録は申請IDと再提出の回数で決まるID(論点18。作り直しの変換と同じ採番)。
        $usageId = LeaveUsageId::for('special_leave', $command->requestId, $aggregate->designationCountForRequest($command->requestId));

        $aggregate
            ->designateUsage(
                usageId: $usageId,
                requestId: $command->requestId,
                specialLeaveTypeId: $command->specialLeaveTypeId,
                usedOn: $command->usedOn,
                usageType: $command->usageType,
                usedDays: $command->usedDays,
                usedMinutes: $command->usedMinutes,
                userId: $command->userId,
            )
            ->persist();

        return $usageId;
    }
}
