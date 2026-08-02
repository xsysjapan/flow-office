<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Commands\BackfillAttendanceMonthLockShare;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Console\Command;

/**
 * AttendanceMonthLocked/AttendanceMonthShared導入前に提出済みだった月次勤怠に対し、
 * 事後的にロック・共有を補完する。1回限りの手動実行を想定し、cron登録はしない。
 */
class BackfillAttendanceMonthLockShareCommand extends Command
{
    protected $signature = 'attendance:backfill-month-lock-share';

    protected $description = 'ロック・共有イベント導入前に提出済みだった月次勤怠へロック・共有を補完する';

    public function handle(CommandBus $commandBus): int
    {
        $count = $commandBus->dispatch(new BackfillAttendanceMonthLockShare);
        $this->info("{$count} 件の月次勤怠にロック・共有を補完しました。");

        return self::SUCCESS;
    }
}
