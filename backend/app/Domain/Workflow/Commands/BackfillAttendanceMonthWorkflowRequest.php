<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * WorkflowRequestによる月次勤怠申請のラップ導入前に提出済みだった月次勤怠に対し、
 * 事後的に対応するworkflow_requestを補完する。1回限りの手動実行を想定し、cron登録はしない。
 */
class BackfillAttendanceMonthWorkflowRequest implements Command
{
    public function __construct() {}
}
