<?php

namespace App\Domain\PaidLeaveRequest\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveRequest\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeaveRequest\Commands\MigratePaidLeaveRequest;

/**
 * 本変更前の有給申請を有給申請の集約へ引き継ぐ。検証(引き継ぎ可能な状態か・二重引き継ぎの禁止)は集約が行う。
 * 既に状態がある申請(status が none 以外)は何もしない(冪等。運用コマンドの再実行で二重に記録しない)。
 *
 * @implements CommandHandler<MigratePaidLeaveRequest>
 */
class MigratePaidLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof MigratePaidLeaveRequest);

        $aggregate = PaidLeaveRequestAggregate::retrieve($command->paidLeaveRequestId);

        if ($aggregate->status() !== 'none') {
            return null;
        }

        $aggregate->migrate(
            userId: $command->userId,
            targetDate: $command->targetDate,
            leaveType: $command->leaveType,
            hours: $command->hours,
            requestedDays: $command->requestedDays,
            approverUserId: $command->approverUserId,
            reason: $command->reason,
            requestGroupId: $command->requestGroupId,
            workflowRequestId: $command->workflowRequestId,
            status: $command->status,
            usageId: $command->usageId,
            hasUsage: $command->hasUsage,
            submittedAt: $command->submittedAt,
            approvedAt: $command->approvedAt,
            returnedAt: $command->returnedAt,
            cancelledAt: $command->cancelledAt,
        )->persist();

        return null;
    }
}
