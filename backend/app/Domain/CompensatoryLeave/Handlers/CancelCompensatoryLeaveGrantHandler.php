<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveGrantAggregate;
use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveGrant;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantStatus;

/**
 * 未使用の確定済み代休Grantを取り消す。RequestCompensatoryLeaveGrantCancellationHandler
 * (承認不要設定時)またはApproveCompensatoryLeaveGrantCancellationHandlerから発行される。
 * どちらの発行元も呼び出し前に検証済みだが、直接発行された場合の防御としてここでも
 * 再検証する。
 *
 * @implements CommandHandler<CancelCompensatoryLeaveGrant>
 */
class CancelCompensatoryLeaveGrantHandler implements CommandHandler
{
    public function handle(Command $command): CompensatoryLeaveGrant
    {
        assert($command instanceof CancelCompensatoryLeaveGrant);

        $grant = CompensatoryLeaveGrant::query()->findOrFail($command->grantId);

        if ($grant->status !== CompensatoryLeaveGrantStatus::CONFIRMED) {
            throw new DomainRuleException('確定済みの代休のみ取消できます。');
        }

        if (! $this->isFullyUnused($grant)) {
            throw new DomainRuleException('既に使用された代休は取り消せません。');
        }

        CompensatoryLeaveGrantAggregate::retrieve($grant->id)
            ->cancel(cancelledByUserId: $command->cancelledByUserId, reason: $command->reason)
            ->persist();

        return $grant->refresh();
    }

    private function isFullyUnused(CompensatoryLeaveGrant $grant): bool
    {
        if (abs((float) $grant->remaining_days - (float) $grant->granted_days) > 0.001) {
            return false;
        }

        if ($grant->granted_minutes !== null
            && abs((int) $grant->remaining_minutes - (int) $grant->granted_minutes) > 0) {
            return false;
        }

        return true;
    }
}
