<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\DesignateCompensatoryLeaveUsage;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use Illuminate\Support\Str;

/**
 * 申請に対応する消化記録を作成する(充当は行わない)。消化記録IDは発行する。
 *
 * viaReactor=true(申請・再提出からのReactor発行)で、同じ申請の有効な消化記録が既にあれば何もしない(冪等)。
 *
 * @implements CommandHandler<DesignateCompensatoryLeaveUsage>
 */
class DesignateCompensatoryLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof DesignateCompensatoryLeaveUsage);

        $aggregate = CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId);

        if ($command->viaReactor && $this->hasActiveUsage($aggregate, $command->requestId)) {
            return null;
        }

        $aggregate
            ->designateUsage(
                usageId: (string) Str::uuid(),
                requestId: $command->requestId,
                usedOn: $command->usedOn,
                usageType: $command->usageType,
                usedDays: $command->usedDays,
                usedMinutes: $command->usedMinutes,
            )
            ->persist();

        return null;
    }

    private function hasActiveUsage(CompensatoryLeaveAccountAggregate $aggregate, string $requestId): bool
    {
        $usageId = $aggregate->usageIdForRequest($requestId);

        return $usageId !== null && in_array($aggregate->usageStatus($usageId), ['designated', 'confirmed'], true);
    }
}
