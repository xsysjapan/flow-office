<?php

namespace App\Domain\PaidLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * usageId・paidLeaveRequestId のどちらか一方は必須(両方nullは例外)。両方指定された場合は usageId を優先する。
 * paidLeaveRequestId 指定時は、その有給申請IDに最後に指定された消化記録を対象にする。
 *
 * viaReactor=true の場合、既に取消済み・消化記録が無ければ何もしない(冪等)。
 */
class CancelPaidLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $usageId,
        public readonly ?string $cancelledByUserId,
        public readonly ?string $reason,
        public readonly ?string $paidLeaveRequestId = null,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {
        if ($usageId === null && $paidLeaveRequestId === null) {
            throw new \InvalidArgumentException('usageId か paidLeaveRequestId のどちらかを指定してください。');
        }
    }
}
