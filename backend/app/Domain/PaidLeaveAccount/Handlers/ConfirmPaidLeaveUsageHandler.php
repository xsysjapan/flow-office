<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;

/**
 * @implements CommandHandler<ConfirmPaidLeaveUsage>
 */
class ConfirmPaidLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ConfirmPaidLeaveUsage);

        $aggregate = PaidLeaveAccountAggregate::retrieve($command->userId);

        $usageId = $command->usageId
            ?? $aggregate->usageIdForRequest((string) $command->paidLeaveRequestId);

        if ($usageId === null) {
            throw new DomainRuleException("有給申請 [{$command->paidLeaveRequestId}] に対応する消化記録が存在しません。");
        }

        // viaReactor=true: 既に確定済みなら何もしない。
        if ($command->viaReactor && $aggregate->usageStatus($usageId) === 'confirmed') {
            return null;
        }

        $aggregate
            ->approveUsage(
                usageId: $usageId,
                confirmedByUserId: $command->confirmedByUserId,
            )
            ->persist();
        return null;
    }
}
