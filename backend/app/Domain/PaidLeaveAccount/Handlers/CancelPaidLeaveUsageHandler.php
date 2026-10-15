<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\CancelPaidLeaveUsage;

/**
 * @implements CommandHandler<CancelPaidLeaveUsage>
 */
class CancelPaidLeaveUsageHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof CancelPaidLeaveUsage);

        $aggregate = PaidLeaveAccountAggregate::retrieve($command->userId);

        $usageId = $command->usageId
            ?? ($command->paidLeaveRequestId !== null
                ? $aggregate->usageIdForRequest($command->paidLeaveRequestId)
                : null);

        // viaReactor=true: 消化記録が無い・既に取消済みなら何もしない。
        if ($command->viaReactor) {
            $status = $usageId === null ? null : $aggregate->usageStatus($usageId);

            if ($status === null || $status === 'cancelled') {
                return null;
            }
        }

        if ($usageId === null) {
            throw new DomainRuleException("有給申請 [{$command->paidLeaveRequestId}] に対応する消化記録が存在しません。");
        }

        $aggregate
            ->cancelUsage(
                usageId: $usageId,
                cancelledByUserId: $command->cancelledByUserId,
                reason: $command->reason,
            )
            ->persist();
        return null;
    }
}
