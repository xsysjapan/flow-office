<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveGrantAggregate;
use App\Domain\SpecialLeave\Commands\RevokeSpecialLeaveGrant;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveGrantStatus;

/**
 * 管理者が発行済みの特別休暇付与を取り消す。既に消化された分がある場合は労働者の
 * 既得権を損なうため取消を許可しない(RevokePaidLeaveGrantHandlerと同じ考え方)。
 *
 * @implements CommandHandler<RevokeSpecialLeaveGrant>
 */
class RevokeSpecialLeaveGrantHandler implements CommandHandler
{
    public function handle(Command $command): SpecialLeaveGrant
    {
        assert($command instanceof RevokeSpecialLeaveGrant);

        $grant = SpecialLeaveGrant::query()->findOrFail($command->grantId);

        if ($grant->status === SpecialLeaveGrantStatus::REVOKED) {
            throw new DomainRuleException('この特別休暇付与は既に取り消し済みです。');
        }

        if ((float) $grant->used_days > 0) {
            throw new DomainRuleException('既に消化された分は取り消せません。');
        }

        SpecialLeaveGrantAggregate::retrieve($grant->id)
            ->revoke(revokedByUserId: $command->revokedByUserId, reason: $command->reason)
            ->persist();

        return $grant->refresh();
    }
}
