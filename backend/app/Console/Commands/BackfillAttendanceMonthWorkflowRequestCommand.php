<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\BackfillAttendanceMonthWorkflowRequest;
use Illuminate\Console\Command;

/**
 * WorkflowRequestによる月次勤怠申請のラップ導入前に提出済みだった月次勤怠に対し、
 * 事後的に対応するworkflow_requestを補完する。1回限りの手動実行を想定し、cron登録はしない。
 */
class BackfillAttendanceMonthWorkflowRequestCommand extends Command
{
    protected $signature = 'workflow:backfill-attendance-month-request';

    protected $description = 'WorkflowRequest導入前に提出済みだった月次勤怠へ対応するworkflow_requestを補完する';

    public function handle(CommandBus $commandBus): int
    {
        $count = $commandBus->dispatch(new BackfillAttendanceMonthWorkflowRequest);
        $this->info("{$count} 件の月次勤怠にworkflow_requestを補完しました。");

        return self::SUCCESS;
    }
}
