<?php

namespace App\Domain\SpecialLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇の申請の承認に伴い、消化記録を確定する(付与への充当。残数不足でも確定し、不足量は未充当として記録する。論点17)。
 *
 * requiresGrantは種別が残数を要するか(承認時の種別マスタの値)。viaReactor=true のとき、既に確定済みなら何もしない(冪等)。
 */
class ConfirmSpecialLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $requestId,
        public readonly bool $requiresGrant,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
