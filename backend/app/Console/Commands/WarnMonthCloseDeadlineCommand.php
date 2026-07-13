<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Commands\WarnMonthCloseDeadline;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Console\Command;

/**
 * UC-N001「月次締め前警告」: 前月分の月次勤怠の締め切りが近づいたら管理部へ警告する。
 * cronから毎日実行する想定。
 */
class WarnMonthCloseDeadlineCommand extends Command
{
    protected $signature = 'attendance:warn-month-close-deadline';

    protected $description = '月次勤怠の締め切りが近い場合に警告を通知する';

    public function handle(CommandBus $commandBus): int
    {
        $count = $commandBus->dispatch(new WarnMonthCloseDeadline);
        $this->info("{$count} 件の月次締め前警告を通知しました。");

        return self::SUCCESS;
    }
}
