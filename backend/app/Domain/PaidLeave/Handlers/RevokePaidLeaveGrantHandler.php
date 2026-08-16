<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Aggregates\PaidLeaveGrantAggregate;
use App\Domain\PaidLeave\Commands\RevokePaidLeaveGrant;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantStatus;

/**
 * 管理者が発行済みの有給付与を取り消す。既に消化された分がある場合は労働者の既得権を
 * 損なうため取消を許可しない。
 *
 * @implements CommandHandler<RevokePaidLeaveGrant>
 */
class RevokePaidLeaveGrantHandler implements CommandHandler
{
    public function handle(Command $command): PaidLeaveGrant
    {
        assert($command instanceof RevokePaidLeaveGrant);

        $grant = PaidLeaveGrant::query()->findOrFail($command->grantId);

        if ($grant->status === PaidLeaveGrantStatus::REVOKED) {
            throw new DomainRuleException('この有給付与は既に取り消し済みです。');
        }

        if ((float) $grant->used_days > 0) {
            throw new DomainRuleException('既に消化された分は取り消せません。');
        }

        PaidLeaveGrantAggregate::retrieve($grant->id)
            ->revoke(revokedByUserId: $command->revokedByUserId, reason: $command->reason)
            ->persist();

        return $grant->refresh();
    }
}
