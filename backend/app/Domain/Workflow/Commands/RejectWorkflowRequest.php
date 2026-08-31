<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-L09相当: 承認者が申請を却下する(編集・再提出不可の終端状態。spec 論点2-2)。
 * 全申請種別で共通利用可能な汎用Commandだが、現時点では備品貸出申請(asset_loan)のみが
 * 却下ボタンをUIに露出する。
 */
class RejectWorkflowRequest implements Command
{
    public function __construct(
        public readonly string $workflowRequestId,
        public readonly string $rejectedByUserId,
        public readonly string $reason,
    ) {}
}
