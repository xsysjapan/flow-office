<?php

namespace App\Domain\CompensatoryLeave\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * compensatory_leave.grant_cancelled
 *
 * 未使用の確定済みGrantの取消申請が承認されたことを表す。status=cancelledにし、
 * 残数を0にする(CancelCompensatoryLeaveGrantHandler参照)。
 */
class CompensatoryLeaveGrantCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $cancelledByUserId,
        public readonly ?string $reason,
    ) {}
}
