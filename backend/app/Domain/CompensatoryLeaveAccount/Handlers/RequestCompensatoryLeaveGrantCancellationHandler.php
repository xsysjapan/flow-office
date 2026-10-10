<?php

namespace App\Domain\CompensatoryLeaveAccount\Handlers;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Commands\RequestCompensatoryLeaveGrantCancellation;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\CompensatoryLeaveGrantCancellation;
use App\Models\CompensatoryLeaveGrantCancellationStatus;
use App\Models\SystemSetting;

/**
 * 未使用の確定済み代休の取消を申請する。承認不要設定のときは申請行を作らず、その場で付与を取り消す
 * (口座集約のcancelGrant)。承認が必要なときは取消申請の行(pending)を作る(承認は
 * ApproveCompensatoryLeaveGrantCancellationが行う)。
 *
 * 付与の状態・使用の判定は口座集約の問い合わせで行う(他の文脈の値は読まない)。
 *
 * @implements CommandHandler<RequestCompensatoryLeaveGrantCancellation>
 */
class RequestCompensatoryLeaveGrantCancellationHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof RequestCompensatoryLeaveGrantCancellation);

        $aggregate = CompensatoryLeaveAccountAggregate::retrieve(CompensatoryLeaveAccountAggregate::streamIdFor($command->userId))
            ->forUser($command->userId);

        if ($command->requestedByUserId !== $command->userId) {
            throw new DomainRuleException('自分の代休のみ取消申請できます。');
        }

        if ($aggregate->grantStatus($command->grantId) !== 'confirmed') {
            throw new DomainRuleException('確定済みの代休のみ取消申請できます。');
        }

        if (! $aggregate->isGrantUnused($command->grantId)) {
            throw new DomainRuleException('既に使用された代休は取り消せません。');
        }

        if (SystemSetting::current()->compensatory_leave_requires_approval) {
            CompensatoryLeaveGrantCancellation::query()->create([
                'grant_id' => $command->grantId,
                'requested_by_user_id' => $command->requestedByUserId,
                'status' => CompensatoryLeaveGrantCancellationStatus::PENDING,
                'reason' => $command->reason,
            ]);

            return null;
        }

        $aggregate->cancelGrant($command->grantId, $command->requestedByUserId, $command->reason)->persist();

        return null;
    }
}
