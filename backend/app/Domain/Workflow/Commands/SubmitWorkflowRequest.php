<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W002: 社員が申請する(申請)。承認者は都度指定できる。
 *
 * `viaReactor=true`は、休暇申請の提出(`.shared`)などをReactorが連鎖させる場合に使う。
 * 申請者本人のチェックは行わず、下書き(DRAFT)以外(提出済み等)の状態なら何もしない(冪等)。
 * 記録する提出者IDは`initiatedByUserId`(連鎖の起点となった操作者ID)を使う。
 */
class SubmitWorkflowRequest implements Command
{
    public function __construct(
        public readonly string $workflowRequestId,
        public readonly string $submittedByUserId,
        public readonly ?string $approverUserId = null,
        public readonly bool $viaReactor = false,
        public readonly ?string $initiatedByUserId = null,
    ) {}
}
