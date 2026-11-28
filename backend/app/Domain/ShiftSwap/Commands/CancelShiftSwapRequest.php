<?php

namespace App\Domain\ShiftSwap\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * `viaReactor=true`は、ワークフローの取消を受けて取り消すReactorが使う。申請者本人のチェックは行わず、
 * 提出済み以外(取消済み・承認済み等)なら何もしない(冪等)。
 */
class CancelShiftSwapRequest implements Command
{
    public function __construct(
        public readonly string $shiftSwapRequestId,
        public readonly string $cancelledByUserId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
