<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 同じ社員への複数の付与を、1回で記録する(付与予定のまとめて付与する2件)。1件でも失敗したら、何も記録しない。
 * 返り値は付与IDの配列(渡した順)。
 */
class GrantPaidLeaves implements Command
{
    /**
     * @param  array<int, array{grantId: string, grantedOn: string, expiresOn: string, grantedDays: float, grantReason: ?string, source?: string}>  $grants
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $grants,
    ) {}
}
