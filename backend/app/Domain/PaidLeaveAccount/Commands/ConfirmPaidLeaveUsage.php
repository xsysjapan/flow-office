<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * usageId・paidLeaveRequestId のどちらか一方は必須(両方nullは例外)。両方指定された場合は usageId を優先する。
 * paidLeaveRequestId 指定時は、その有給申請IDに最後に指定された消化記録を対象にする。
 *
 * viaReactor=true の場合、既に確定済みなら何もしない(冪等)。
 */
class ConfirmPaidLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $usageId,
        public readonly ?string $confirmedByUserId,
        public readonly ?string $paidLeaveRequestId = null,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {
        if ($usageId === null && $paidLeaveRequestId === null) {
            throw new \InvalidArgumentException('usageId か paidLeaveRequestId のどちらかを指定してください。');
        }
    }
}
