<?php

namespace App\Domain\SpecialLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 特別休暇の申請に対応する消化記録を取り消す(差戻し・取消。確定済みなら充当を解除して残数を戻す)。
 *
 * viaReactor=true のとき、対応する消化記録が無い・既に取消済みなら何もしない(冪等)。
 */
class CancelSpecialLeaveUsage implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly string $requestId,
        public readonly ?string $reason,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
