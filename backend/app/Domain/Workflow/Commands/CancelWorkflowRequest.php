<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W005: 申請者が申請を取り消す。
 *
 * `viaReactor=true`は、業務側の取消(休暇申請の取消等)をReactorが連鎖させる場合に使う。
 * 申請者本人・権限のチェックは行わず、既に取消済み・取消不可の状態なら何もしない(冪等)。
 * `initiatedByUserId`は連鎖の起点となった操作者ID(Reactorが引き継ぐ)。
 */
class CancelWorkflowRequest implements Command
{
    public function __construct(
        public readonly string $workflowRequestId,
        public readonly string $cancelledByUserId,
        public readonly string $reason,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
