<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\WarnFiveDayObligation;
use Illuminate\Console\Command;

/**
 * UC-P006: 年5日取得義務を警告する。cronから毎日実行する想定。
 */
class WarnFiveDayObligationCommand extends Command
{
    protected $signature = 'paid-leave:warn-five-day-obligation';

    protected $description = '年5日取得義務を満たしていない有給付与について警告を通知する';

    public function handle(CommandBus $commandBus): int
    {
        $count = $commandBus->dispatch(new WarnFiveDayObligation);
        $this->info("{$count} 件の年5日取得義務警告を通知しました。");

        return self::SUCCESS;
    }
}
