<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Commands\ApproveCompensatoryLeaveGrantCancellation;
use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveGrant;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\CompensatoryLeaveGrantCancellation;
use App\Models\CompensatoryLeaveGrantCancellationStatus;
use Illuminate\Support\Carbon;

/**
 * 代休Grantの取消申請を承認し、CancelCompensatoryLeaveGrantを発行する。
 *
 * @implements CommandHandler<ApproveCompensatoryLeaveGrantCancellation>
 */
class ApproveCompensatoryLeaveGrantCancellationHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function handle(Command $command): CompensatoryLeaveGrantCancellation
    {
        assert($command instanceof ApproveCompensatoryLeaveGrantCancellation);

        $cancellation = CompensatoryLeaveGrantCancellation::query()->findOrFail($command->cancellationId);

        if ($cancellation->status !== CompensatoryLeaveGrantCancellationStatus::PENDING) {
            throw new DomainRuleException('未承認の取消申請のみ承認できます。');
        }

        $this->commandBus->dispatch(new CancelCompensatoryLeaveGrant(
            grantId: $cancellation->grant_id,
            cancelledByUserId: $command->approvedByUserId,
            reason: $cancellation->reason,
        ));

        $cancellation->update([
            'status' => CompensatoryLeaveGrantCancellationStatus::APPROVED,
            'approver_user_id' => $command->approvedByUserId,
            'approved_at' => Carbon::now(),
        ]);

        return $cancellation->refresh();
    }
}
