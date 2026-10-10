<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Commands\RevokeSpecialLeaveGrant;
use App\Domain\SpecialLeaveAccount\Commands\RevokeSpecialLeaveAccountGrant;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveGrantStatus;

/**
 * 管理者が発行済みの特別休暇付与を取り消す。取消の記録は利用者単位の特別休暇口座の集約が行い、
 * 既に消化された分がある付与は口座集約が拒否する(労働者の既得権を損なうため)。
 *
 * @implements CommandHandler<RevokeSpecialLeaveGrant>
 */
class RevokeSpecialLeaveGrantHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

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

        $this->commandBus->dispatch(new RevokeSpecialLeaveAccountGrant(
            userId: (string) $grant->user_id,
            grantId: (string) $grant->id,
            revokedByUserId: $command->revokedByUserId,
            reason: $command->reason,
        ));

        return $grant->refresh();
    }
}
