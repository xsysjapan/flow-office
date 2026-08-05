<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Commands\CancelCompensatoryLeaveGrant;
use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeaveGrantCancellation;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveGrantCancellation;
use App\Models\CompensatoryLeaveGrantCancellationStatus;
use App\Models\CompensatoryLeaveGrantStatus;
use App\Models\SystemSetting;

/**
 * 未使用の確定済み代休Grantの取消を申請する。承認不要設定の場合はその場で
 * CancelCompensatoryLeaveGrantを発行し即時確定する(承認要の場合は
 * compensatory_leave_grant_cancellationsに申請行を作るのみで、Grant自体はまだ変更しない)。
 *
 * @implements CommandHandler<RequestCompensatoryLeaveGrantCancellation>
 */
class RequestCompensatoryLeaveGrantCancellationHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function handle(Command $command): CompensatoryLeaveGrant|CompensatoryLeaveGrantCancellation
    {
        assert($command instanceof RequestCompensatoryLeaveGrantCancellation);

        $grant = CompensatoryLeaveGrant::query()->findOrFail($command->grantId);

        if ($grant->user_id !== $command->requestedByUserId) {
            throw new DomainRuleException('自分の代休のみ取消申請できます。');
        }

        if ($grant->status !== CompensatoryLeaveGrantStatus::CONFIRMED) {
            throw new DomainRuleException('確定済みの代休のみ取消申請できます。');
        }

        if (! $this->isFullyUnused($grant)) {
            throw new DomainRuleException('既に使用された代休は取り消せません。');
        }

        if (SystemSetting::current()->compensatory_leave_requires_approval) {
            return CompensatoryLeaveGrantCancellation::query()->create([
                'grant_id' => $grant->id,
                'requested_by_user_id' => $command->requestedByUserId,
                'status' => CompensatoryLeaveGrantCancellationStatus::PENDING,
                'reason' => $command->reason,
            ]);
        }

        return $this->commandBus->dispatch(new CancelCompensatoryLeaveGrant(
            grantId: $grant->id,
            cancelledByUserId: $command->requestedByUserId,
            reason: $command->reason,
        ));
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
