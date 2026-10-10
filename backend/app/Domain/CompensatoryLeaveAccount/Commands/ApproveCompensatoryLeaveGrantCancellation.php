<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 代休付与の取消申請を承認し、付与を取り消す。userIdは付与の利用者(口座集約の対象)。
 */
class ApproveCompensatoryLeaveGrantCancellation implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly int $cancellationId,
        public readonly string $approvedByUserId,
    ) {}
}
