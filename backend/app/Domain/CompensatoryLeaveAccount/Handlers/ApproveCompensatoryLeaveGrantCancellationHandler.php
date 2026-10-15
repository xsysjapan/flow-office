<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\ApproveCompensatoryLeaveGrantCancellation;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\CompensatoryLeaveGrantCancellation;
use App\Models\CompensatoryLeaveGrantCancellationStatus;
use Illuminate\Support\Carbon;

/**
 * 代休付与の取消申請を承認し、付与を口座集約で取り消す(未使用のみ。判定は口座集約の`cancelGrant`)。
 *
 * @implements CommandHandler<ApproveCompensatoryLeaveGrantCancellation>
 */
class ApproveCompensatoryLeaveGrantCancellationHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ApproveCompensatoryLeaveGrantCancellation);

        $cancellation = CompensatoryLeaveGrantCancellation::query()->findOrFail($command->cancellationId);

        if ($cancellation->status !== CompensatoryLeaveGrantCancellationStatus::PENDING) {
            throw new DomainRuleException('未承認の取消申請のみ承認できます。');
        }

        CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId)
            ->cancelGrant($cancellation->grant_id, $command->approvedByUserId, $cancellation->reason)
            ->persist();

        $cancellation->update([
            'status' => CompensatoryLeaveGrantCancellationStatus::APPROVED,
            'approver_user_id' => $command->approvedByUserId,
            'approved_at' => Carbon::now(),
        ]);

        return null;
    }
}
