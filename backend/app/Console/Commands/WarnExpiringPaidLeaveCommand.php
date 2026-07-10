<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\WarnExpiringPaidLeave;
use Illuminate\Console\Command;

/**
 * UC-P005: 有給消滅警告を出す。cronから毎日実行する想定。
 */
class WarnExpiringPaidLeaveCommand extends Command
{
    protected $signature = 'paid-leave:warn-expiring';

    protected $description = '有効期限が近い有給休暇の消滅警告を通知する';

    public function handle(CommandBus $commandBus): int
    {
        $count = $commandBus->dispatch(new WarnExpiringPaidLeave);
        $this->info("{$count} 件の消滅警告を通知しました。");

        return self::SUCCESS;
    }
}
