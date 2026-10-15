<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 管理者が代休付与を直接取り消す(承認フローを経由しない)。未使用の確定済み付与だけを取り消せる。
 */
class RevokeCompensatoryLeaveAccountGrant implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly string $cancelledByUserId,
        public readonly ?string $reason = null,
    ) {}
}
