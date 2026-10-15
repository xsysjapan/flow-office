<?php

namespace App\Domain\SpecialLeave\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇申請を差し戻す(ワークフローの差戻しからのReactor発行が通常の経路)。
 * viaReactor=true のとき、既に差戻し中なら何もしない(冪等)。
 */
class ReturnSpecialLeaveRequest implements Command
{
    public function __construct(
        public readonly string $specialLeaveRequestId,
        public readonly string $returnedByUserId,
        public readonly string $comment,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
