<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class GrantPaidLeave implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantedOn,
        public readonly string $expiresOn,
        public readonly float $grantedDays,
        public readonly ?string $grantReason,
        public readonly string $source = 'manual',
        /** 付与IDの指定(省略時は新規UUID)。一括付与は付与予定の行IDから決めたUUIDを渡し、再実行時の二重付与を防ぐ。 */
        public readonly ?string $grantId = null,
    ) {}
}
