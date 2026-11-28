<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 承認者が申請をまとめて承認する(全部か無しか)。各件は`ApproveWorkflowRequest`で行い、業務側の承認
 * (月次勤怠・経費・休暇・振替休日等)は承認イベントを受けるReactorの連鎖で行われる。
 * 開始時点で提出済みだった申請が、同じ一括の前の件の連鎖(まとめ申請の兄弟)で既に承認済みになっていたら飛ばす。
 */
class ApproveWorkflowRequests implements Command
{
    /**
     * @param  list<string>  $workflowRequestIds
     */
    public function __construct(
        public readonly array $workflowRequestIds,
        public readonly string $approvedByUserId,
    ) {}
}
