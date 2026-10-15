<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W003: 承認者が申請を承認する。
 *
 * `viaReactor=true`は、休暇申請のまとめ申請の兄弟承認などReactorが連鎖させる場合に使う。
 * 承認者本人のチェックは行わず、既に承認済み・提出済み以外の状態なら何もしない(冪等)。
 * 記録する承認者IDは`initiatedByUserId`(連鎖の起点となった操作者ID)を使う。
 */
class ApproveWorkflowRequest implements Command
{
    public function __construct(
        public readonly string $workflowRequestId,
        // nullは、経費精算のapproval_skip_threshold等による自動承認(承認者確認を1段階省略)を表す。
        public readonly ?string $approvedByUserId,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
