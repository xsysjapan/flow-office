<?php

namespace App\Domain\BackOffice\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-B001: バックオフィスタスクを自動作成する。
 * workflow_request.approved イベントを受けて App\Listeners\CreateBackOfficeTaskOnApproval から発行される。
 */
class CreateBackOfficeTaskFromApproval implements Command
{
    public function __construct(public readonly string $workflowRequestId) {}
}
