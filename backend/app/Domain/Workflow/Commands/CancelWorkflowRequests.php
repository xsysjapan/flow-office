<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 申請者が自分の申請をまとめて取り消す(全部か無しか)。各件は`CancelWorkflowRequest`と同じ検査・記録で行い、
 * 1件でも取り消せない申請があれば何も記録せず BulkOperationRejected になる。
 * 開始時点で取消可能だった申請が、同じ一括の前の件の連鎖で既に取消済みになっていたら飛ばす。
 */
class CancelWorkflowRequests implements Command
{
    /**
     * @param  list<string>  $workflowRequestIds
     */
    public function __construct(
        public readonly array $workflowRequestIds,
        public readonly string $cancelledByUserId,
        public readonly string $reason,
    ) {}
}
