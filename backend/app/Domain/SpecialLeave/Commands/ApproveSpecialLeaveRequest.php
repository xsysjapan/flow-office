<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇申請を承認する。approvedByUserIdは承認者(承認不要時の即時承認はnull)。
 * viaReactor=true(ワークフローの承認・まとめ申請の兄弟承認からのReactor発行)のときは承認者チェックを行わず、
 * 既に承認済みなら何もしない(冪等)。
 */
class ApproveSpecialLeaveRequest implements Command
{
    public function __construct(
        public readonly string $specialLeaveRequestId,
        public readonly ?string $approvedByUserId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
