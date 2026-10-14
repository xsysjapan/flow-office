<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 付与時の未充当の充当(論点19 決定10-1)。指定した付与の残りの範囲で、確定済み・未取消の未充当の消化記録へ
 * 確定の古い順に充当する。
 *
 * viaReactor=true の場合、充当できるものが無ければ何もしない(冪等)。
 */
class AllocateUnallocatedPaidLeaveUsages implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $grantId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
