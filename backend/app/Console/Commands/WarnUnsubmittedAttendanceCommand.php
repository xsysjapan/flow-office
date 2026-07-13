<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Commands\WarnUnsubmittedAttendance;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Console\Command;

/**
 * UC-N001「勤怠未提出」: 前月分の勤怠がまだ提出されていない社員に警告する。cronから毎日実行する想定。
 */
class WarnUnsubmittedAttendanceCommand extends Command
{
    protected $signature = 'attendance:warn-unsubmitted';

    protected $description = '前月分の勤怠がまだ提出されていない社員に警告を通知する';

    public function handle(CommandBus $commandBus): int
    {
        $count = $commandBus->dispatch(new WarnUnsubmittedAttendance);
        $this->info("{$count} 件の勤怠未提出警告を通知しました。");

        return self::SUCCESS;
    }
}
