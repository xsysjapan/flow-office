<?php

namespace App\Domain\CompensatoryLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 未使用の確定済みGrantを取り消す。RequestCompensatoryLeaveGrantCancellationHandler
 * (承認不要設定時)またはApproveCompensatoryLeaveGrantCancellationHandlerから発行される。
 */
class CancelCompensatoryLeaveGrant implements Command
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $cancelledByUserId,
        public readonly ?string $reason,
    ) {}
}
